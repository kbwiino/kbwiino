<?php
declare(strict_types=1);

namespace App\Services\AI;

final class AIInvalidResponse extends AIException
{
    /** @param string[] $problems */
    public function __construct(public readonly array $problems)
    {
        parent::__construct('invalid_response', 'The AI response did not match the required structure and was discarded.', false);
    }
}
