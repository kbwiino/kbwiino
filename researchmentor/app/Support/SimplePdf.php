<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Minimal text-only PDF writer (Helvetica, A4, automatic wrapping & pagination).
 * Enough for reports without pulling in a heavy PDF dependency.
 */
final class SimplePdf
{
    private const W = 595.28;
    private const H = 841.89;
    private const M = 52.0;
    private const WIDTHS = [32 => 278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556, 1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556, 333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556, 556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584];

    /** @var array<int,array<int,string>> pages => list of content-stream operators */
    private array $pages = [];
    private float $y;
    private string $footer;

    public function __construct(private string $title, string $footer = '')
    {
        $this->footer = $footer;
        $this->newPage();
    }

    private function newPage(): void
    {
        $this->pages[] = [];
        $this->y = self::H - self::M;
    }

    public function heading(string $text, int $level = 1): self
    {
        $size = [1 => 17, 2 => 13.5, 3 => 11.5][$level] ?? 11.5;
        $this->space($level === 1 ? 6 : 10);
        $this->write($text, $size, true, 0, $size * 1.3);
        $this->space(3);
        return $this;
    }

    public function paragraph(string $text, float $size = 10.5, bool $bold = false, float $indent = 0): self
    {
        foreach (preg_split('/\R/u', $text) ?: [''] as $line) {
            if (trim($line) === '') {
                $this->space(5);
                continue;
            }
            $this->write($line, $size, $bold, $indent, $size * 1.38);
        }
        $this->space(4);
        return $this;
    }

    public function bullet(string $text, float $size = 10.5): self
    {
        $this->write('-  ' . $text, $size, false, 10, $size * 1.38, 22);
        return $this;
    }

    public function keyValue(string $label, string $value): self
    {
        $this->write($label . ': ' . $value, 10.5, false, 0, 14.5);
        return $this;
    }

    public function note(string $text): self
    {
        $this->space(4);
        $this->write($text, 9.5, false, 8, 13, 14, true);
        $this->space(6);
        return $this;
    }

    public function space(float $pts): self
    {
        $this->y -= $pts;
        return $this;
    }

    public function pageBreak(): self
    {
        $this->newPage();
        return $this;
    }

    private function write(string $text, float $size, bool $bold, float $indent, float $lead, float $hang = 0, bool $italic = false): void
    {
        $maxW = self::W - 2 * self::M - $indent;
        $lines = $this->wrap($this->encode($text), $size, $bold, $maxW, $hang);
        foreach ($lines as $i => $line) {
            if ($this->y - $lead < self::M + 22) {
                $this->newPage();
            }
            $this->y -= $lead;
            $x = self::M + $indent + ($i > 0 ? $hang : 0);
            $font = $bold ? 'F2' : ($italic ? 'F3' : 'F1');
            $this->pages[count($this->pages) - 1][] = sprintf('BT /%s %.2F Tf %.2F %.2F Td (%s) Tj ET', $font, $size, $x, $this->y, $this->escape($line));
        }
    }

    private function encode(string $s): string
    {
        $s = str_replace(["\u{2018}", "\u{2019}", "\u{201C}", "\u{201D}", "\u{2013}", "\u{2014}", "\u{2022}", "\u{2026}", "\t"], ["'", "'", '"', '"', '-', '-', '-', '...', '    '], $s);
        $out = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $s);
        return $out === false ? preg_replace('/[^\x20-\x7E]/', '?', $s) : $out;
    }

    private function escape(string $s): string
    {
        return str_replace(['\\', '(', ')', "\r"], ['\\\\', '\\(', '\\)', ''], $s);
    }

    private function textWidth(string $s, float $size, bool $bold): float
    {
        $w = 0;
        $n = strlen($s);
        for ($i = 0; $i < $n; $i++) {
            $w += self::WIDTHS[ord($s[$i])] ?? 556;
        }
        return $w * $size / 1000 * ($bold ? 1.06 : 1.0);
    }

    /** @return string[] */
    private function wrap(string $s, float $size, bool $bold, float $maxW, float $hang): array
    {
        $words = preg_split('/ +/', $s) ?: [];
        $lines = [];
        $cur = '';
        foreach ($words as $word) {
            // hard-split overly long tokens
            while ($this->textWidth($word, $size, $bold) > $maxW) {
                $cut = max(1, (int) (strlen($word) * $maxW / max(1, $this->textWidth($word, $size, $bold))) - 1);
                if ($cur !== '') {
                    $lines[] = $cur;
                    $cur = '';
                }
                $lines[] = substr($word, 0, $cut);
                $word = substr($word, $cut);
            }
            $try = $cur === '' ? $word : $cur . ' ' . $word;
            $limit = $maxW - (count($lines) > 0 ? $hang : 0);
            if ($this->textWidth($try, $size, $bold) <= $limit) {
                $cur = $try;
            } else {
                $lines[] = $cur;
                $cur = $word;
            }
        }
        $lines[] = $cur;
        return $lines;
    }

    public function render(): string
    {
        $objs = [];
        $n = count($this->pages);
        // object ids: 1 catalog, 2 pages, 3 F1, 4 F2, 5 F3, 6 info, then page/content pairs
        $kids = [];
        for ($i = 0; $i < $n; $i++) {
            $kids[] = (7 + $i * 2) . ' 0 R';
        }
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $n . ' >>';
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $objs[5] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Oblique /Encoding /WinAnsiEncoding >>';
        $objs[6] = '<< /Title (' . $this->escape($this->encode($this->title)) . ') /Producer (ResearchMentor AI) /CreationDate (D:' . gmdate('YmdHis') . 'Z) >>';
        foreach ($this->pages as $i => $ops) {
            $footer = sprintf('BT /F1 8 Tf %.2F %.2F Td (%s) Tj ET', self::M, 30, $this->escape($this->encode($this->footer . '   Page ' . ($i + 1) . ' of ' . $n)));
            $stream = implode("\n", $ops) . "\n" . $footer;
            $pageId = 7 + $i * 2;
            $objs[$pageId] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R /F3 5 0 R >> >> /Contents %d 0 R >>', self::W, self::H, $pageId + 1);
            $objs[$pageId + 1] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
        }
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        ksort($objs);
        foreach ($objs as $id => $body) {
            $offsets[$id] = strlen($out);
            $out .= "$id 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($out);
        $max = max(array_keys($objs));
        $out .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $out .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R /Info 6 0 R >>\nstartxref\n$xref\n%%EOF\n";
        return $out;
    }
}
