<?php $appName = (string) setting('app.name', 'NextaLeadMap'); ?><!DOCTYPE html>
<html lang="en" class="h-full bg-slate-50 antialiased">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="<?= e(session_status() === PHP_SESSION_ACTIVE ? csrf_token() : '') ?>">
    <title><?= e(($title ?? '') !== '' ? $title . ' · ' : '') ?><?= e($appName) ?></title>
    <link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <script src="<?= e(asset('js/app.js')) ?>" nonce="<?= e(nonce()) ?>" defer></script>
</head>
<body class="min-h-full text-slate-800">
<div class="relative flex min-h-screen items-center justify-center overflow-hidden px-4 py-10">
    <div class="pointer-events-none absolute -top-40 -left-32 h-96 w-96 rounded-full bg-indigo-300/40 blur-3xl"></div>
    <div class="pointer-events-none absolute -bottom-40 -right-32 h-96 w-96 rounded-full bg-violet-300/40 blur-3xl"></div>
    <div class="relative w-full max-w-md">
        <div class="mb-8 flex flex-col items-center text-center">
            <div class="grid h-12 w-12 place-items-center rounded-2xl bg-gradient-to-br from-indigo-600 to-violet-600 text-white shadow-lg shadow-indigo-300"><?= icon('map-pin', 'w-6 h-6') ?></div>
            <div class="mt-3 text-xl font-bold text-slate-900"><?= e($appName) ?></div>
            <div class="text-sm text-slate-500">Business lead research &amp; enrichment</div>
        </div>
        <?php if (session_status() === PHP_SESSION_ACTIVE): ?><?= \App\Core\View::partial('partials/flash') ?><?php endif; ?>
        <?= $content ?>
    </div>
</div>
</body>
</html>
