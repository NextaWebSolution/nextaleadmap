<?php

declare(strict_types=1);

/*
 * NextaLeadMap bootstrap — shared by public/index.php and the CLI tools.
 * Uses Composer's autoloader when present, otherwise a built-in PSR-4 loader (no Composer needed on cPanel).
 */

if (PHP_VERSION_ID < 80300) {
    http_response_code(500);
    exit('NextaLeadMap requires PHP 8.3 or newer. This server runs PHP ' . PHP_VERSION . '.');
}

define('NLM_ROOT', __DIR__);

if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require __DIR__ . '/vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class): void {
        if (!str_starts_with($class, 'App\\')) {
            return;
        }
        $rel = str_replace('\\', '/', substr($class, 4));
        if (str_contains($rel, '..')) {
            return;
        }
        $file = __DIR__ . '/src/' . $rel . '.php';
        if (is_file($file)) {
            require $file;
        }
    });
    require __DIR__ . '/src/helpers.php';
}

App\Core\App::boot(__DIR__);
