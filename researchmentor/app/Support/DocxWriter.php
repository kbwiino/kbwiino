<?php
declare(strict_types=1);

namespace App\Support;

use ZipArchive;

/** Minimal DOCX (WordprocessingML) writer: headings, paragraphs, bullets, tables, notes, footer with page numbers. */
final class DocxWriter
{
    private string $body = '';

    public function __construct(private string $title, private string $footer = '')
    {
    }

    public static function x(string $s): string
    {
        $s = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $s) ?? '';
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function run(string $text, bool $bold = false, bool $italic = false, ?string $color = null, ?int $size = null): string
    {
        $pr = ($bold ? '<w:b/>' : '') . ($italic ? '<w:i/>' : '') . ($color ? '<w:color w:val="' . $color . '"/>' : '') . ($size ? '<w:sz w:val="' . $size . '"/>' : '');
        $parts = explode("\n", $text);
        $out = '';
        foreach ($parts as $i => $p) {
            $out .= '<w:r>' . ($pr ? '<w:rPr>' . $pr . '</w:rPr>' : '') . ($i > 0 ? '<w:br/>' : '') . '<w:t xml:space="preserve">' . self::x($p) . '</w:t></w:r>';
        }
        return $out;
    }

    private function para(string $runs, string $style = '', string $extra = ''): void
    {
        $this->body .= '<w:p><w:pPr>' . ($style ? '<w:pStyle w:val="' . $style . '"/>' : '') . $extra . '</w:pPr>' . $runs . '</w:p>';
    }

    public function title(string $t): self { $this->para($this->run($t), 'Title'); return $this; }
    public function heading(string $t, int $level = 1): self { $this->para($this->run($t), 'Heading' . max(1, min(3, $level))); return $this; }
    public function paragraph(string $t, bool $bold = false): self { $this->para($this->run($t, $bold)); return $this; }
    public function bullet(string $t): self { $this->para($this->run("\u{2022}  " . $t), '', '<w:ind w:left="480" w:hanging="260"/>'); return $this; }
    public function keyValue(string $k, string $v): self { $this->para($this->run($k . ': ', true) . $this->run($v)); return $this; }

    public function note(string $t): self
    {
        $this->para($this->run($t, false, true, '444444'), '', '<w:pBdr><w:left w:val="single" w:sz="18" w:space="8" w:color="6F42C1"/></w:pBdr><w:shd w:val="clear" w:color="auto" w:fill="F4F0FA"/><w:ind w:left="200"/>');
        return $this;
    }

    /** @param string[] $headers @param array<int,string[]> $rows */
    public function table(array $headers, array $rows): self
    {
        $cell = fn(string $t, bool $head = false) => '<w:tc><w:tcPr>' . ($head ? '<w:shd w:val="clear" w:color="auto" w:fill="DCE6F5"/>' : '') . '</w:tcPr><w:p>' . $this->run($t, $head, false, null, 18) . '</w:p></w:tc>';
        $tbl = '<w:tbl><w:tblPr><w:tblStyle w:val="TableGrid"/><w:tblW w:w="5000" w:type="pct"/></w:tblPr><w:tblGrid>' . str_repeat('<w:gridCol/>', count($headers)) . '</w:tblGrid>';
        $tbl .= '<w:tr><w:trPr><w:tblHeader/></w:trPr>' . implode('', array_map(fn($h) => $cell($h, true), $headers)) . '</w:tr>';
        foreach ($rows as $r) {
            $tbl .= '<w:tr>' . implode('', array_map(fn($c) => $cell((string) $c), array_pad($r, count($headers), ''))) . '</w:tr>';
        }
        $this->body .= $tbl . '</w:tbl><w:p/>';
        return $this;
    }

    public function render(): string
    {
        $ns = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';
        $doc = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document ' . $ns . '><w:body>' . $this->body
            . '<w:sectPr><w:footerReference w:type="default" r:id="rId2"/><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134" w:header="567" w:footer="567" w:gutter="0"/></w:sectPr></w:body></w:document>';
        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles ' . $ns . '><w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:cs="Calibri"/><w:sz w:val="22"/><w:lang w:val="en-GB"/></w:rPr></w:rPrDefault><w:pPrDefault><w:pPr><w:spacing w:after="120" w:line="264" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults>'
            . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style>'
            . '<w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/><w:basedOn w:val="Normal"/><w:pPr><w:spacing w:after="200"/></w:pPr><w:rPr><w:b/><w:color w:val="1D3B6F"/><w:sz w:val="40"/></w:rPr></w:style>'
            . '<w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/><w:pPr><w:keepNext/><w:spacing w:before="320" w:after="120"/><w:outlineLvl w:val="0"/></w:pPr><w:rPr><w:b/><w:color w:val="1D3B6F"/><w:sz w:val="30"/></w:rPr></w:style>'
            . '<w:style w:type="paragraph" w:styleId="Heading2"><w:name w:val="heading 2"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/><w:pPr><w:keepNext/><w:spacing w:before="240" w:after="80"/><w:outlineLvl w:val="1"/></w:pPr><w:rPr><w:b/><w:color w:val="1D3B6F"/><w:sz w:val="26"/></w:rPr></w:style>'
            . '<w:style w:type="paragraph" w:styleId="Heading3"><w:name w:val="heading 3"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/><w:pPr><w:keepNext/><w:spacing w:before="160" w:after="60"/><w:outlineLvl w:val="2"/></w:pPr><w:rPr><w:b/><w:sz w:val="23"/></w:rPr></w:style>'
            . '<w:style w:type="table" w:styleId="TableGrid"><w:name w:val="Table Grid"/><w:tblPr><w:tblBorders><w:top w:val="single" w:sz="4" w:color="999999"/><w:left w:val="single" w:sz="4" w:color="999999"/><w:bottom w:val="single" w:sz="4" w:color="999999"/><w:right w:val="single" w:sz="4" w:color="999999"/><w:insideH w:val="single" w:sz="4" w:color="999999"/><w:insideV w:val="single" w:sz="4" w:color="999999"/></w:tblBorders><w:tblCellMar><w:left w:w="80" w:type="dxa"/><w:right w:w="80" w:type="dxa"/></w:tblCellMar></w:tblPr></w:style></w:styles>';
        $footer = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:ftr ' . $ns . '><w:p><w:pPr><w:jc w:val="center"/></w:pPr>' . $this->run($this->footer . '   Page ', false, false, '666666', 16)
            . '<w:r><w:rPr><w:color w:val="666666"/><w:sz w:val="16"/></w:rPr><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText xml:space="preserve"> PAGE </w:instrText></w:r><w:r><w:fldChar w:fldCharType="end"/></w:r></w:p></w:ftr>';
        $path = tempnam(sys_get_temp_dir(), 'rmdocx');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/><Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>');
        $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>' . self::x($this->title) . '</dc:title><dc:creator>ResearchMentor AI</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created></cp:coreProperties>');
        $zip->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer1.xml"/></Relationships>');
        $zip->addFromString('word/document.xml', $doc);
        $zip->addFromString('word/styles.xml', $styles);
        $zip->addFromString('word/footer1.xml', $footer);
        $zip->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);
        return $bytes;
    }
}
