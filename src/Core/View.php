<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/** Plain-PHP templates. Templates must escape output with e(). */
final class View
{
    public static function render(string $template, array $data = [], ?string $layout = 'layouts/app'): string
    {
        $content = self::partial($template, $data);
        if ($layout === null) {
            return $content;
        }
        return self::partial($layout, $data + ['content' => $content]);
    }

    public static function partial(string $template, array $data = []): string
    {
        if (!preg_match('#^[a-z0-9_/-]+$#i', $template) || str_contains($template, '..')) {
            throw new RuntimeException('Invalid template');
        }
        $file = App::$root . '/views/' . $template . '.php';
        if (!is_file($file)) {
            throw new RuntimeException('View not found: ' . $template);
        }
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            include $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }

    public static function page(string $template, array $data = [], int $status = 200): Response
    {
        return Response::html(self::render($template, $data), $status);
    }

    public static function error(int $code, string $message): Response
    {
        try {
            $layout = App::$user ? 'layouts/app' : 'layouts/auth';
            return Response::html(self::render('errors/error', ['code' => $code, 'message' => $message, 'title' => (string) $code], $layout), $code);
        } catch (\Throwable) {
            return Response::html('<h1>' . $code . '</h1><p>' . e($message) . '</p>', $code);
        }
    }
}
