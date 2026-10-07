<?php
declare(strict_types=1);

namespace App\Agents;

interface ReviewAgent
{
    public function type(): string;

    public function label(): string;

    /** Whether a document with extracted text is required. */
    public function needsDocument(): bool;

    /** Prompt template variables beyond the common ones. */
    public function variables(AiRunContext $ctx, string $documentText): array;

    /** Called after persistence, e.g. to create derived records. */
    public function afterSave(AiRunContext $ctx, array $reviewRow, array $review): void;
}
