<?php
declare(strict_types=1);

namespace App\Support;

final class UploadedFile
{
    public function __construct(
        public readonly string $tmpName,
        public readonly string $originalName,
        public readonly int $size,
        public readonly int $error,
        private readonly bool $testing = false,
    ) {
    }

    public function isValid(): bool
    {
        return $this->error === UPLOAD_ERR_OK
            && is_file($this->tmpName)
            && ($this->testing || is_uploaded_file($this->tmpName));
    }

    public function moveTo(string $dest): bool
    {
        if (!$this->isValid()) {
            return false;
        }
        return $this->testing ? copy($this->tmpName, $dest) : move_uploaded_file($this->tmpName, $dest);
    }

    public function errorMessage(): string
    {
        return match ($this->error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file exceeds the maximum upload size.',
            UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            default => 'The upload failed.',
        };
    }
}
