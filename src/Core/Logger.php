<?php

declare(strict_types=1);

namespace App\Core;

/** JSON-lines file logger with secret redaction. */
final class Logger
{
    private const REDACT = '/(api[_-]?key|password|secret|token|authorization)(["\']?\s*[:=]\s*["\']?)([^"\'&\s,}]+)/i';

    public function __construct(private string $dir)
    {
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $line = json_encode([
            'ts' => gmdate('c'),
            'level' => $level,
            'msg' => self::redact($message),
            'ctx' => json_decode(self::redact((string) json_encode($context, JSON_PARTIAL_OUTPUT_ON_ERROR)), true),
        ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        @file_put_contents($this->dir . '/app-' . gmdate('Y-m-d') . '.log', $line . "\n", FILE_APPEND | LOCK_EX);
    }

    public static function redact(string $s): string
    {
        $s = (string) preg_replace(self::REDACT, '$1$2[redacted]', $s);
        return (string) preg_replace('#(https?://)[^/@\s:]+:[^/@\s]+@#', '$1[redacted]@', $s);
    }

    public function info(string $m, array $c = []): void
    {
        $this->log('info', $m, $c);
    }

    public function warning(string $m, array $c = []): void
    {
        $this->log('warning', $m, $c);
    }

    public function error(string $m, array $c = []): void
    {
        $this->log('error', $m, $c);
    }
}
