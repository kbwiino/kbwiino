<?php
declare(strict_types=1);

namespace App\Services\Extraction;

use App\Support\Config;
use Smalot\PdfParser\Parser;
use ZipArchive;

/** Extracts text segments from supported document types (PDF, DOCX, TXT, CSV, XLSX). */
final class TextExtractor
{
    public const MAX_PAGES = 1500;
    public const MAX_CHARS = 8_000_000;

    /** @return array{segments:Segment[],pages:?int} */
    public static function extract(string $path, string $ext): array
    {
        return match ($ext) {
            'pdf' => self::pdf($path),
            'docx' => self::docx($path),
            'xlsx' => self::xlsx($path),
            'csv' => self::csv($path),
            'txt' => self::txt($path),
            default => throw new \RuntimeException("Unsupported extension {$ext}"),
        };
    }

    private static function pdf(string $path): array
    {
        @set_time_limit(180);
        $pdf = (new Parser())->parseFile($path);
        $pages = $pdf->getPages();
        if (count($pages) > self::MAX_PAGES) {
            throw new \RuntimeException('The PDF has too many pages to process.');
        }
        $segments = [];
        $section = null;
        $chars = 0;
        foreach ($pages as $i => $page) {
            $text = trim(preg_replace("/[ \t]+/", ' ', (string) $page->getText()) ?? '');
            if ($text === '') {
                continue;
            }
            $chars += strlen($text);
            if ($chars > self::MAX_CHARS) {
                break;
            }
            foreach (SectionDetector::segmentText($text, $i + 1, $section) as $seg) {
                $segments[] = $seg;
                $section = $seg->section;
            }
        }
        if (!$segments) {
            throw new \RuntimeException('No extractable text found (scanned image PDFs need OCR, which is not supported).');
        }
        return ['segments' => $segments, 'pages' => count($pages)];
    }

    private static function openZip(string $path): ZipArchive
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new \RuntimeException('Could not open the document container.');
        }
        \App\Services\UploadValidator::assertSafeZip($zip);
        return $zip;
    }

    private static function loadXml(string $xml): \DOMDocument
    {
        $dom = new \DOMDocument();
        // LIBXML_NONET blocks network access; entities are not substituted (no LIBXML_NOENT) => no XXE.
        if (!$dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
            throw new \RuntimeException('Malformed document XML.');
        }
        return $dom;
    }

    private static function docx(string $path): array
    {
        $zip = self::openZip($path);
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if ($xml === false) {
            throw new \RuntimeException('word/document.xml missing.');
        }
        $dom = self::loadXml($xml);
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $lines = [];
        foreach ($xp->query('//w:body//w:p') as $p) {
            $text = '';
            foreach ($xp->query('.//w:t | .//w:tab | .//w:br', $p) as $n) {
                $text .= $n->localName === 't' ? $n->textContent : ($n->localName === 'tab' ? "\t" : "\n");
            }
            $style = $xp->query('./w:pPr/w:pStyle/@w:val', $p)->item(0)?->nodeValue ?? '';
            $text = trim($text);
            if ($text !== '' && preg_match('/^(Heading|Title)/i', $style)) {
                $text = $text; // headings are detected by text; also mark explicitly below
            }
            $lines[] = ($style !== '' && preg_match('/^Heading\d/i', $style) && $text !== '' && SectionDetector::heading($text) === null) ? "# {$text}" : $text;
            if (strlen(implode("\n", $lines)) > self::MAX_CHARS) {
                break;
            }
        }
        $full = implode("\n", $lines);
        if (trim($full) === '') {
            throw new \RuntimeException('The document contains no text.');
        }
        // Treat "# Heading" lines (Word heading styles) as section labels too.
        $segments = [];
        $section = null;
        $buf = '';
        $segSection = null;
        foreach ($lines as $line) {
            if (str_starts_with($line, '# ')) {
                $section = substr($line, 2);
                $line = $section;
            } elseif (($h = SectionDetector::heading($line)) !== null) {
                $section = $h;
            }
            if ($buf === '') {
                $segSection = $section;
            }
            $buf .= $line . "\n";
            if (mb_strlen($buf) >= 3500 && $line === '') {
                $segments[] = new Segment(null, $segSection, trim($buf));
                $buf = '';
            }
        }
        if (trim($buf) !== '') {
            $segments[] = new Segment(null, $segSection, trim($buf));
        }
        // Word has no fixed pages; estimate for reference only.
        return ['segments' => $segments, 'pages' => null];
    }

    /** @return array<string,array<int,array<int,string>>> sheet name => rows of cell strings */
    public static function xlsxSheets(string $path, int $rowCap = 2000): array
    {
        $zip = self::openZip($path);
        $shared = [];
        if (($ss = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            foreach (self::loadXml($ss)->getElementsByTagName('si') as $si) {
                $shared[] = $si->textContent;
            }
        }
        $wb = self::loadXml((string) $zip->getFromName('xl/workbook.xml'));
        $rels = [];
        if (($r = $zip->getFromName('xl/_rels/workbook.xml.rels')) !== false) {
            foreach (self::loadXml($r)->getElementsByTagName('Relationship') as $rel) {
                $rels[$rel->getAttribute('Id')] = $rel->getAttribute('Target');
            }
        }
        $sheets = [];
        $sheetNo = 0;
        foreach ($wb->getElementsByTagName('sheet') as $sheet) {
            $sheetNo++;
            $name = $sheet->getAttribute('name');
            $rid = $sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
            $target = $rels[$rid] ?? "worksheets/sheet{$sheetNo}.xml";
            $target = ltrim(str_starts_with($target, '/') ? substr($target, 1) : 'xl/' . $target, '/');
            $xml = $zip->getFromName($target);
            if ($xml === false) {
                continue;
            }
            $rows = [];
            foreach (self::loadXml($xml)->getElementsByTagName('row') as $row) {
                if (count($rows) >= $rowCap) {
                    break;
                }
                $cells = [];
                foreach ($row->getElementsByTagName('c') as $c) {
                    $t = $c->getAttribute('t');
                    $v = $c->getElementsByTagName('v')->item(0)?->textContent ?? '';
                    if ($t === 's') {
                        $v = $shared[(int) $v] ?? '';
                    } elseif ($t === 'inlineStr') {
                        $v = $c->textContent;
                    }
                    $cells[] = $v;
                }
                $rows[] = $cells;
            }
            $sheets[$name] = $rows;
        }
        $zip->close();
        return $sheets;
    }

    private static function xlsx(string $path): array
    {
        $segments = [];
        foreach (self::xlsxSheets($path, 2000) as $name => $rows) {
            $text = implode("\n", array_map(fn($r) => implode("\t", $r), $rows));
            foreach (str_split($text, 6000) as $chunk) {
                $segments[] = new Segment(null, 'Sheet: ' . $name, $chunk);
            }
        }
        if (!$segments) {
            throw new \RuntimeException('The workbook contains no data.');
        }
        return ['segments' => $segments, 'pages' => null];
    }

    private static function readText(string $path): string
    {
        $text = (string) file_get_contents($path, false, null, 0, self::MAX_CHARS);
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }
        return $text;
    }

    private static function csv(string $path): array
    {
        $text = self::readText($path);
        $lines = preg_split('/\R/u', $text) ?: [];
        $segments = [];
        foreach (array_chunk($lines, 80) as $i => $chunk) {
            $segments[] = new Segment(null, $i === 0 ? 'CSV header and first rows' : 'CSV rows ' . ($i * 80 + 1) . '+', implode("\n", $chunk));
        }
        if (!$segments) {
            throw new \RuntimeException('The CSV file is empty.');
        }
        return ['segments' => $segments, 'pages' => null];
    }

    private static function txt(string $path): array
    {
        $text = trim(self::readText($path));
        if ($text === '') {
            throw new \RuntimeException('The text file is empty.');
        }
        return ['segments' => SectionDetector::segmentText($text), 'pages' => null];
    }
}
