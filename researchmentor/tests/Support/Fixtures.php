<?php
declare(strict_types=1);

namespace Tests\Support;

use App\Support\SimplePdf;
use ZipArchive;

/** Builds real (tiny) PDF/DOCX/XLSX/CSV/TXT files for upload tests. */
final class Fixtures
{
    private static array $tmp = [];

    public static function file(string $name, string $bytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'rmfx');
        file_put_contents($path, $bytes);
        self::$tmp[] = $path;
        return ['file' => ['tmp_name' => $path, 'name' => $name, 'size' => strlen($bytes), 'error' => UPLOAD_ERR_OK, '_test' => true]];
    }

    public static function txt(string $name = 'chapter.txt', ?string $text = null): array
    {
        return self::file($name, $text ?? "Chapter 1 Introduction\n\n1.1 Background\nMobile money has grown quickly in Uganda.\n\n1.2 Problem statement\nRural households save little.\n");
    }

    public static function pdf(string $name = 'proposal.pdf', array $pages = ['Chapter 1 Introduction', '3.4 Sampling design']): array
    {
        $pdf = new SimplePdf('Test');
        foreach ($pages as $i => $text) {
            if ($i > 0) $pdf->pageBreak();
            $pdf->paragraph($text);
            $pdf->paragraph('This page discusses mobile money and savings among rural households in Uganda.');
        }
        return self::file($name, $pdf->render());
    }

    public static function docx(string $name = 'thesis.docx', array $paragraphs = ['Chapter 3 Methodology', '3.4 Sampling', 'A stratified random sample of 400 households was selected.']): array
    {
        $body = '';
        foreach ($paragraphs as $p) {
            $body .= '<w:p><w:r><w:t>' . htmlspecialchars($p, ENT_XML1) . '</w:t></w:r></w:p>';
        }
        $zipPath = tempnam(sys_get_temp_dir(), 'rmdx');
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/></Types>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>' . $body . '</w:body></w:document>');
        $zip->close();
        $bytes = file_get_contents($zipPath);
        unlink($zipPath);
        return self::file($name, $bytes);
    }

    public static function xlsx(string $name = 'data.xlsx'): array
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'rmxl');
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/></Types>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Survey" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="x" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>age</t></si><si><t>savings</t></si></sst>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row><row r="2"><c r="A2"><v>25</v></c><c r="B2"><v>1200</v></c></row><row r="3"><c r="A3"><v>31</v></c><c r="B3"><v>3400</v></c></row></sheetData></worksheet>');
        $zip->close();
        $bytes = file_get_contents($zipPath);
        unlink($zipPath);
        return self::file($name, $bytes);
    }

    public static function csv(string $name = 'data.csv', ?string $csv = null): array
    {
        return self::file($name, $csv ?? "age,savings,region\n25,1200,North\n31,3400,East\n42,800,West\n29,2100,North\n");
    }

    public static function cleanup(): void
    {
        foreach (self::$tmp as $p) {
            @unlink($p);
        }
        self::$tmp = [];
    }
}
