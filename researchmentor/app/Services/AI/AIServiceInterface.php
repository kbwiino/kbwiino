<?php
declare(strict_types=1);

namespace App\Services\AI;

interface AIServiceInterface
{
    /** Free-text generation. $options: purpose, user_id, project_id, job_id, max_tokens */
    public function generateText(string $system, string $user, array $options = []): AIResult;

    /**
     * Generate JSON that conforms to $schema (JSON Schema). The result is decoded but NOT trusted:
     * callers must validate it (see ReviewSchema) before persisting.
     */
    public function generateStructuredResponse(string $system, string $user, array $schema, array $options = []): AIResult;

    /** Convenience for review agents: structured response using the standard review schema for $reviewType. */
    public function createReview(string $reviewType, string $system, string $user, array $options = []): AIResult;

    /** Token count for a prompt when the provider supports it; null otherwise. */
    public function countTokensIfSupported(string $system, string $user): ?int;

    /** @return array{available:bool,provider:string,model:string,mock:bool,reason:?string} */
    public function checkAvailability(): array;

    public function isMock(): bool;

    public function provider(): string;

    public function model(): string;
}
