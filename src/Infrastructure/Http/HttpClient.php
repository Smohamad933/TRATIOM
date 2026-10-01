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
        ] + self::tlsOptions());

        $response = curl_exec($ch);
        if ($response === false && in_array(curl_errno($ch), [60, 77], true)) {
            $error = curl_error($ch);
            throw new RuntimeException("HTTP request to {$url} failed: {$error}. " . self::TLS_HINT);
        }
        if ($response === false) {
            $error = curl_error($ch);
            throw new RuntimeException("HTTP request to {$url} failed: {$error}");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        return ['status' => $status, 'body' => (string) $response, 'json' => json_decode((string) $response, true)];
    }

    /** @return array{status: int, body: string, json: mixed} */
    public function postJson(string $url, array $body, array $headers = []): array
    {
        return $this->request('POST', $url, $body, $headers);
    }

    /** Lets the installer apply its form choice before the new .env exists. */
    public static ?bool $verifyOverride = null;

    public const TLS_HINT = 'SSL certificate check failed. If an antivirus/firewall scans HTTPS (Kaspersky, ESET, ...), disable HTTPS scanning for php-cgi.exe or set HTTP_CA_FILE to its root certificate (.pem). Last resort: HTTP_SSL_VERIFY=false in .env';

    /**
     * Certificate verification options.
     *  - HTTP_CA_FILE: custom CA bundle (e.g. an antivirus/proxy root certificate in PEM)
     *  - otherwise php.ini curl.cainfo / openssl.cafile if set
     *  - otherwise the bundled resources/cacert.pem (Mozilla CA list) — fixes Windows PHP with no CA store
     *  - HTTP_SSL_VERIFY=false disables verification (insecure; last resort)
     *
     * @return array<int, mixed>
     */
    public static function tlsOptions(): array
    {
        $v = self::$verifyOverride ?? (function_exists('env') ? env('HTTP_SSL_VERIFY', true) : true);
        if ($v === false || in_array(strtolower((string) $v), ['false', '0', 'off', 'no'], true)) {
            return [CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0];
        }
        $opts = [CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2];
        $custom = (string) (function_exists('env') ? env('HTTP_CA_FILE', '') : '');
        if ($custom !== '' && is_file($custom)) {
            $opts[CURLOPT_CAINFO] = $custom;
        } elseif ((string) ini_get('curl.cainfo') === '' && (string) ini_get('openssl.cafile') === '') {
            $bundled = dirname(__DIR__, 3) . '/resources/cacert.pem';
            if (is_file($bundled)) {
                $opts[CURLOPT_CAINFO] = $bundled;
            }
        }
        return $opts;
    }
}
