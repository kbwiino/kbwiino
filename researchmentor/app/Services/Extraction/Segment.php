<?php
declare(strict_types=1);

namespace App\Services\Extraction;

final class Segment
{
    public function __construct(public ?int $page, public ?string $section, public string $content)
    {
    }
}
