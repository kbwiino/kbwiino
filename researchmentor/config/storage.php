<?php
use App\Support\Env;

$root = dirname(__DIR__);
return [
    'private_path' => rtrim(Env::get('PRIVATE_STORAGE_PATH', $root . '/storage/private'), '/'),
    'export_path' => rtrim(Env::get('EXPORT_STORAGE_PATH', $root . '/storage/exports'), '/'),
    'log_path' => $root . '/storage/logs',
    'max_upload_bytes' => Env::int('MAX_UPLOAD_MB', 25) * 1024 * 1024,
    // extension => [allowed MIME types]
    'allowed_types' => [
        'pdf' => ['application/pdf'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'txt' => ['text/plain'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        'csv' => ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'],
    ],
];
