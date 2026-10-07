<?php
declare(strict_types=1);

namespace App\Services\AI;

final class CurlTransport implements HttpTransport
{
    public function post(string $url, array $headers, string $body, int $timeoutSeconds): array
    {
        $ch = curl_init($url);
        $respHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,   // never disabled
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$respHeaders) {
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $respHeaders[strtolower(trim($k))] = trim($v);
                }
                return strlen($line);
            },
        ]);
        $out = curl_exec($ch);
        if ($out === false) {
            $errno = curl_errno($ch);
            curl_close($ch);
            $timeout = $errno === CURLE_OPERATION_TIMEDOUT;
            // Do not include curl_error(): it can echo the URL; the message below is safe.
            throw new AIException($timeout ? 'timeout' : 'network_error', $timeout ? 'The AI provider timed out.' : 'Could not reach the AI provider.', true);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'headers' => $respHeaders, 'body' => (string) $out];
    }
}
