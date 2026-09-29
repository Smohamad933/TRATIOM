<?php

declare(strict_types=1);

namespace Terrarium\Infrastructure\Http;

final class Request
{
    /** @var array<string, string> route parameters */
    public array $params = [];

    /** @var array<string, mixed> request-scoped attributes (e.g. authenticated user) */
    public array $attributes = [];

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @param array<string, string> $headers lower-cased header names
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $body = [],
        public readonly array $headers = [],
        public readonly string $ip = ''
    ) {}

    public static function fromGlobals(): self
    {
        // On IIS + URL Rewrite, the original URL is exposed as HTTP_X_ORIGINAL_URL
        $uri = $_SERVER['HTTP_X_ORIGINAL_URL'] ?? $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url((string) $uri, PHP_URL_PATH) ?: '/';
        $path = '/' . trim(rawurldecode($path), '/');

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            }
        }
        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $k => $h) {
            if (isset($_SERVER[$k])) {
                $headers[$h] = (string) $_SERVER[$k];
            }
        }
        // Some FastCGI setups only expose the Authorization header via REDIRECT_HTTP_AUTHORIZATION
        if (!isset($headers['authorization']) && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers['authorization'] = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        $body = $_POST;
        if (str_contains($headers['content-type'] ?? '', 'application/json')) {
            $raw = file_get_contents('php://input') ?: '';
            $decoded = $raw === '' ? [] : json_decode($raw, true);
            if (!is_array($decoded)) {
                throw HttpException::badRequest('بدنه درخواست JSON معتبر نیست.');
            }
            $body = $decoded;
        }

        return new self(
            method: strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            path: $path,
            query: $_GET,
            body: $body,
            headers: $headers,
            ip: (string) ($_SERVER['REMOTE_ADDR'] ?? '')
        );
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function bearerToken(): ?string
    {
        $auth = $this->header('authorization', '');
        if ($auth !== null && preg_match('/^Bearer\s+(\S+)$/i', $auth, $m)) {
            return $m[1];
        }
        return null;
    }

    /** Returns a trimmed string input or throws 422 when missing. */
    public function requireString(string $key, string $label, int $maxLength = 255): string
    {
        $value = $this->input($key);
        if (!is_string($value) && !is_int($value)) {
            throw HttpException::unprocessable("فیلد «{$label}» الزامی است.", ['field' => $key]);
        }
        $value = trim((string) $value);
        if ($value === '') {
            throw HttpException::unprocessable("فیلد «{$label}» الزامی است.", ['field' => $key]);
        }
        if (mb_strlen($value) > $maxLength) {
            throw HttpException::unprocessable("فیلد «{$label}» بیش از حد طولانی است.", ['field' => $key]);
        }
        return $value;
    }
}
