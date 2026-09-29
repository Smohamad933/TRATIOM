<?php

declare(strict_types=1);

namespace Terrarium\Infrastructure\Http;

use RuntimeException;

/**
 * Minimal cURL-based HTTP client used for payment gateways and SMS providers.
 */
class HttpClient
{
    public function __construct(private readonly int $timeoutSeconds = 20) {}

    /**
     * @param array<string, mixed>|string|null $body array => JSON (or form when $form = true)
     * @param array<string, string> $headers
     * @return array{status: int, body: string, json: mixed}
     */
    public function request(string $method, string $url, array|string|null $body = null, array $headers = [], bool $form = false): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL extension is not enabled (extension=curl in php.ini).');
        }

        $ch = curl_init($url);
        $headerLines = ['Accept: application/json'];
        foreach ($headers as $k => $v) {
            $headerLines[] = $k . ': ' . $v;
        }

        if ($body !== null) {
            if (is_array($body)) {
                if ($form) {
                    $payload = http_build_query($body);
                    $headerLines[] = 'Content-Type: application/x-www-form-urlencoded';
                } else {
                    $payload = (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $headerLines[] = 'Content-Type: application/json';
                }
            } else {
                $payload = $body;
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("HTTP request to {$url} failed: {$error}");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return ['status' => $status, 'body' => (string) $response, 'json' => json_decode((string) $response, true)];
    }

    /** @return array{status: int, body: string, json: mixed} */
    public function postJson(string $url, array $body, array $headers = []): array
    {
        return $this->request('POST', $url, $body, $headers);
    }
}
