<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\{Config, HttpException, UploadedFile};
use ZipArchive;

/** Validates uploads: size, extension allow-list, detected MIME, file signature and container structure. */
final class UploadValidator
{
    public const MAX_ZIP_ENTRIES = 5000;
    public const MAX_ZIP_UNCOMPRESSED = 200 * 1024 * 1024;

    /** @return array{extension:string,mime:string,size:int,sha256:string,display_name:string} */
    public static function validate(UploadedFile $f, ?array $allowedExt = null): array
    {
        if ($f->error !== UPLOAD_ERR_OK) {
            throw HttpException::unprocessable($f->errorMessage(), ['file' => [$f->errorMessage()]]);
        }
        if (!$f->isValid()) {
            throw HttpException::unprocessable('Invalid upload.', ['file' => ['The upload could not be verified.']]);
        }
        $max = (int) Config::get('storage.max_upload_bytes');
        $size = (int) filesize($f->tmpName);
        if ($size <= 0) {
            throw HttpException::unprocessable('The file is empty.', ['file' => ['The file is empty.']]);
        }
        if ($size > $max) {
            throw new HttpException(413, 'file_too_large', 'The file exceeds the maximum size of ' . round($max / 1048576) . ' MB.');
        }
        $types = Config::get('storage.allowed_types');
        $display = self::sanitizeName($f->originalName);
        $ext = strtolower(pathinfo($display, PATHINFO_EXTENSION));
        $allowed = $allowedExt ?? array_keys($types);
        if (!in_array($ext, $allowed, true) || !isset($types[$ext])) {
            throw HttpException::unprocessable('Unsupported file type.', ['file' => ['Allowed types: ' . strtoupper(implode(', ', $allowed))]]);
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($f->tmpName) ?: 'application/octet-stream';
        if (!in_array($mime, $types[$ext], true)) {
            throw HttpException::unprocessable('The file content does not match its extension.', ['file' => ["Detected type {$mime} is not valid for .{$ext}"]]);
        }
        self::checkSignature($f->tmpName, $ext);
        return [
            'extension' => $ext,
            'mime' => $ext === 'docx' ? $types['docx'][0] : ($ext === 'xlsx' ? $types['xlsx'][0] : ($ext === 'pdf' ? 'application/pdf' : ($ext === 'csv' ? 'text/csv' : 'text/plain'))),
            'size' => $size,
            'sha256' => hash_file('sha256', $f->tmpName),
            'display_name' => $display,
        ];
    }

    public static function sanitizeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = preg_replace('/[^\p{L}\p{N}._\- ()]/u', '_', $name) ?? '';
        $name = trim($name, " .");
        if ($name === '') {
            $name = 'file';
        }
        return mb_substr($name, 0, 200);
    }

    private static function checkSignature(string $path, string $ext): void
    {
        $fh = fopen($path, 'rb');
        $head = fread($fh, 8192) ?: '';
        fclose($fh);
        $fail = fn(string $why) => throw HttpException::unprocessable('The file appears to be corrupt or not a genuine ' . strtoupper($ext) . ' file.', ['file' => [$why]]);

        switch ($ext) {
            case 'pdf':
                if (!str_starts_with(ltrim($head, "\xEF\xBB\xBF\r\n\t "), '%PDF-')) {
                    $fail('Missing PDF header.');
                }
                break;
            case 'docx':
            case 'xlsx':
                if (!str_starts_with($head, "PK\x03\x04")) {
                    $fail('Missing ZIP signature.');
                }
                $zip = new ZipArchive();
                if ($zip->open($path, ZipArchive::RDONLY) !== true) {
                    $fail('Not a valid Office Open XML container.');
                }
                self::assertSafeZip($zip);
                $need = $ext === 'docx' ? 'word/document.xml' : 'xl/workbook.xml';
                if ($zip->locateName($need) === false || $zip->locateName('[Content_Types].xml') === false) {
                    $zip->close();
                    $fail("Missing {$need}.");
                }
                $zip->close();
                break;
            case 'txt':
            case 'csv':
                if (str_contains($head, "\0")) {
                    $fail('Binary content in a text file.');
                }
                if (!mb_check_encoding($head, 'UTF-8') && !self::looksLikeLatin1($head)) {
                    $fail('Unsupported text encoding (use UTF-8).');
                }
                break;
        }
    }

    private static function looksLikeLatin1(string $s): bool
    {
        // Latin-1/Windows-1252 text has no control characters other than whitespace.
        return !preg_match('/[\x00-\x08\x0B\x0E-\x1F]/', $s);
    }

    /** Defend against zip bombs and path traversal inside Office containers. */
    public static function assertSafeZip(ZipArchive $zip): void
    {
        if ($zip->numFiles > self::MAX_ZIP_ENTRIES) {
            throw HttpException::unprocessable('The document structure is too large.');
        }
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $st = $zip->statIndex($i);
            $total += (int) $st['size'];
            if ($total > self::MAX_ZIP_UNCOMPRESSED || str_contains($st['name'], '..')) {
                throw HttpException::unprocessable('The document structure is not accepted.');
            }
        }
    }
}
