<?php

declare(strict_types=1);

namespace App\Core;

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public static function valid(?string $token): bool
    {
        $expected = $_SESSION['_csrf'] ?? '';
        return is_string($token) && $expected !== '' && hash_equals($expected, $token);
    }
}
