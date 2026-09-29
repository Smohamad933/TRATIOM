<?php

declare(strict_types=1);

namespace Terrarium\Support;

/**
 * Minimal, dependency-free .env loader.
 * Supports comments, quoted values, "export " prefix and ${VAR} interpolation.
 * Real environment variables (e.g. set in IIS FastCGI settings) always win over the file.
 */
final class Env
{
    /** @var array<string, string> */
    private static array $values = [];

    public static function load(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = substr($line, 7);
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }

            $key = trim(substr($line, 0, $pos));
            $raw = trim(substr($line, $pos + 1));

            $quote = ($raw !== '' && ($raw[0] === '"' || $raw[0] === "'")) ? $raw[0] : null;
            if ($quote !== null) {
                $end = strpos($raw, $quote, 1);
                $value = $end === false ? substr($raw, 1) : substr($raw, 1, $end - 1);
                if ($quote === '"') {
                    $value = str_replace('\\\\', '\\', $value);
                }
            } else {
                // strip inline comments for unquoted values
                $value = trim((string) preg_replace('/\s+#.*$/u', '', $raw));
            }

            // single-quoted values are literal; double-quoted/unquoted values support ${VAR}
            if ($quote !== "'") {
                $value = (string) preg_replace_callback(
                    '/\$\{([A-Z0-9_]+)\}/i',
                    fn (array $m): string => (string) (self::raw($m[1]) ?? ''),
                    $value
                );
            }

            self::$values[$key] = $value;
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::raw($key);
        if ($value === null) {
            return $default;
        }

        return match (strtolower($value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $value,
        };
    }

    private static function raw(string $key): ?string
    {
        $real = getenv($key);
        if ($real !== false) {
            return $real;
        }
        return self::$values[$key] ?? null;
    }
}
