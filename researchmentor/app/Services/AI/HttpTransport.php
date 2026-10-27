<?php
declare(strict_types=1);

namespace App\Services\AI;

interface HttpTransport
{
    /**
     * @param string[] $headers "Name: value" lines
     * @return array{status:int,headers:array<string,string>,body:string}
     * @throws AIException on network/timeouts (retryable)
     */
    public function post(string $url, array $headers, string $body, int $timeoutSeconds): array;
}
