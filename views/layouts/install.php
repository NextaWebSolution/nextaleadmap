<?php $steps = [1 => 'Requirements', 2 => 'Database', 3 => 'Admin & app', 4 => 'Done']; $step = $step ?? 1; ?><!DOCTYPE html>
<html lang="en" class="h-full bg-slate-50 antialiased">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($title ?? 'Install') ?> · NextaLeadMap</title>
    <link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <script src="<?= e(asset('js/app.js')) ?>" nonce="<?= e(nonce()) ?>" defer></script>
</head>
<body class="min-h-full text-slate-800">
<div class="mx-auto max-w-2xl px-4 py-10">
    <div class="mb-8 flex items-center gap-3">
        <div class="grid h-11 w-11 place-items-center rounded-2xl bg-gradient-to-br from-indigo-600 to-violet-600 text-white"><?= icon('map-pin', 'w-6 h-6') ?></div>
        <div>
            <div class="text-lg font-bold text-slate-900">NextaLeadMap installer</div>
            <div class="text-sm text-slate-500">Version <?= e(\App\Core\App::version()) ?></div>
        </div>
    </div>
    <ol class="mb-8 grid grid-cols-4 gap-2">
        <?php foreach ($steps as $n => $label): ?>
            <li class="flex flex-col gap-2">
                <div class="h-1.5 rounded-full <?= $n <= $step ? 'bg-indigo-600' : 'bg-slate-200' ?>"></div>
                <span class="text-xs font-medium <?= $n === $step ? 'text-indigo-700' : 'text-slate-500' ?>"><?= $n ?>. <?= e($label) ?></span>
            </li>
        <?php endforeach; ?>
    </ol>
    <div class="card p-6 sm:p-8"><?= $content ?></div>
</div>
</body>
</html>
