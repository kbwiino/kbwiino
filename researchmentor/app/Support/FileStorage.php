<?php
declare(strict_types=1);

namespace App\Support;

/** Private file storage. Stored names are random; original names are never used in paths. */
final class FileStorage
{
    public static function root(string $area = 'docs'): string
    {
        $dir = Config::get('storage.private_path') . '/' . $area;
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Private storage is not writable.');
        }
        return $dir;
    }

    public static function randomName(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function path(string $stored, string $area = 'docs'): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $stored)) {
            throw new \InvalidArgumentException('Invalid stored filename.');
        }
        return self::root($area) . '/' . substr($stored, 0, 2) . '/' . $stored;
    }

    /** Move an uploaded file into private storage; returns the stored name. */
    public static function storeUpload(UploadedFile $file, string $area = 'docs'): string
    {
        $stored = self::randomName();
        $path = self::path($stored, $area);
        if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0750, true) && !is_dir(dirname($path))) {
            throw new \RuntimeException('Could not create storage directory.');
        }
        if (!$file->moveTo($path)) {
            throw new \RuntimeException('Could not store the uploaded file.');
        }
        @chmod($path, 0640);
        return $stored;
    }

    public static function storeContents(string $bytes, string $area = 'exports'): string
    {
        $stored = self::randomName();
        $path = self::path($stored, $area);
        if (!is_dir(dirname($path))) {
            @mkdir(dirname($path), 0750, true);
        }
        file_put_contents($path, $bytes, LOCK_EX);
        @chmod($path, 0640);
        return $stored;
    }

    public static function delete(string $stored, string $area = 'docs'): void
    {
        $p = self::path($stored, $area);
        if (is_file($p)) {
            @unlink($p);
        }
    }
}
