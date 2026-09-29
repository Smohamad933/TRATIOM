<?php

declare(strict_types=1);

namespace Terrarium\Infrastructure\Logging;

use Throwable;

/**
 * Daily rotating file logger: storage/logs/app-YYYY-MM-DD.log
 */
final class Logger
{
    private const LEVELS = ['debug' => 100, 'info' => 200, 'warning' => 300, 'error' => 400, 'critical' => 500];

    public function __construct(
        private readonly string $directory,
        private readonly string $minLevel = 'debug'
    ) {}

    /** @param array<string, mixed> $context */
    public function log(string $level, string $message, array $context = []): void
    {
        if ((self::LEVELS[$level] ?? 0) < (self::LEVELS[$this->minLevel] ?? 100)) {
            return;
        }
        if (isset($context['exception']) && $context['exception'] instanceof Throwable) {
            $e = $context['exception'];
            $context['exception'] = get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
            $context['trace'] = $e->getTraceAsString();
        }
        $line = sprintf(
            "[%s] %s: %s %s\n",
            gmdate('Y-m-d H:i:s'),
            strtoupper($level),
            $message,
            $context ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''
        );
        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0775, true);
        }
        $ok = @file_put_contents($this->directory . '/app-' . gmdate('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
        if ($ok === false) {
            error_log(trim($line));
        }
    }

    public function debug(string $m, array $c = []): void { $this->log('debug', $m, $c); }
    public function info(string $m, array $c = []): void { $this->log('info', $m, $c); }
    public function warning(string $m, array $c = []): void { $this->log('warning', $m, $c); }
    public function error(string $m, array $c = []): void { $this->log('error', $m, $c); }
}
