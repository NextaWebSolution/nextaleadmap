<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use Throwable;

/** Append-only audit trail for security-relevant actions. Never throws. */
final class Audit
{
    public static ?string $ip = null;
    public static ?string $userAgent = null;
    public static string $via = 'web';

    public static function log(string $action, ?string $entityType = null, mixed $entityId = null, array $meta = []): void
    {
        try {
            foreach (['password', 'api_key', 'token', 'secret', 'key'] as $k) {
                if (isset($meta[$k])) {
                    $meta[$k] = '[redacted]';
                }
            }
            App::db()->insert('audit_logs', [
                'user_id' => App::$user['id'] ?? null,
                'action' => mb_substr($action, 0, 100),
                'entity_type' => $entityType,
                'entity_id' => $entityId !== null ? mb_substr((string) $entityId, 0, 64) : null,
                'ip' => self::$ip,
                'user_agent' => self::$userAgent ? mb_substr(self::$userAgent, 0, 255) : null,
                'via' => self::$via,
                'meta' => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) : null,
                'created_at' => now_utc(),
            ]);
        } catch (Throwable $e) {
            App::logger()->error('audit write failed', ['e' => $e->getMessage(), 'action' => $action]);
        }
    }
}
