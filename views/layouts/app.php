<?php
/** @var string $content */
$appName = (string) setting('app.name', 'NextaLeadMap');
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$base = \App\Core\App::basePath();
$rel = '/' . trim(substr($path, strlen($base)), '/');
$active = static fn (string $p): bool => $p === '/' ? $rel === '/' : str_starts_with($rel, $p);
$nav = [
    ['Main', [
        ['/', 'Dashboard', 'dashboard', 'dashboard.view'],
        ['/jobs/create', 'New search', 'search', 'jobs.create'],
        ['/jobs', 'Search jobs', 'briefcase', 'jobs.view'],
        ['/leads', 'Leads', 'target', 'leads.view'],
    ]],
    ['Workspace', [
        ['/api-keys', 'API keys', 'key', 'apikeys.manage'],
        ['/users', 'Users', 'users', 'users.manage'],
        ['/roles', 'Roles', 'shield', 'roles.manage'],
        ['/audit', 'Audit log', 'activity', 'audit.view'],
    ]],
    ['Admin', [
        ['/settings', 'Settings', 'settings', ['settings.manage', 'providers.manage']],
        ['/system', 'System & updates', 'server', 'system.manage'],
    ]],
];
$allowed = static function ($perm): bool {
    foreach ((array) $perm as $p) {
        if (can($p)) {
            return true;
        }
    }
    return false;
};
$user = auth_user();
$initials = strtoupper(implode('', array_map(static fn ($w) => mb_substr($w, 0, 1), array_slice(explode(' ', trim((string) ($user['name'] ?? 'U'))), 0, 2))));
$maint = \App\Core\App::maintenance();
?><!DOCTYPE html>
<html lang="en" class="h-full bg-slate-50 antialiased">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="base-url" content="<?= e(url('')) ?>">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#4338ca">
    <title><?= e(($title ?? '') !== '' ? $title . ' · ' : '') ?><?= e($appName) ?></title>
    <link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <script src="<?= e(asset('js/app.js')) ?>" nonce="<?= e(nonce()) ?>" defer></script>
    <?= vendor_script('alpine') ?>
</head>
<body class="h-full text-slate-800" x-data="{ nav: false }" @keydown.escape.window="nav = false">

<!-- Mobile drawer backdrop -->
<div x-show="nav" x-transition.opacity class="fixed inset-0 z-40 bg-slate-900/50 lg:hidden" @click="nav = false" x-cloak></div>

<!-- Sidebar -->
<aside class="fixed inset-y-0 left-0 z-50 flex w-72 flex-col bg-slate-900 text-slate-300 transition-transform duration-200 -translate-x-full lg:translate-x-0"
       :class="nav && 'translate-x-0!'">
    <div class="flex h-16 items-center gap-3 px-5 border-b border-white/10">
        <div class="grid h-9 w-9 place-items-center rounded-xl bg-gradient-to-br from-indigo-500 to-violet-500 text-white shadow-lg shadow-indigo-900/40">
            <?= icon('map-pin', 'w-5 h-5') ?>
        </div>
        <div class="min-w-0">
            <div class="truncate text-sm font-semibold text-white"><?= e($appName) ?></div>
            <div class="truncate text-[11px] text-slate-400">Lead research platform</div>
        </div>
        <button class="ml-auto rounded-lg p-1.5 text-slate-400 hover:bg-white/10 lg:hidden" @click="nav = false" aria-label="Close menu"><?= icon('x') ?></button>
    </div>
    <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-6">
        <?php foreach ($nav as [$section, $items]):
            $items = array_filter($items, static fn ($i) => $allowed($i[3]));
            if (!$items) { continue; } ?>
            <div>
                <div class="px-3 pb-2 text-[11px] font-semibold uppercase tracking-wider text-slate-500"><?= e($section) ?></div>
                <ul class="space-y-0.5">
                <?php foreach ($items as [$href, $label, $ic]):
                    $on = $href === '/jobs' ? ($active('/jobs') && !$active('/jobs/create')) : $active($href); ?>
                    <li>
                        <a href="<?= e(url($href)) ?>" class="group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition <?= $on ? 'bg-white/10 text-white' : 'hover:bg-white/5 hover:text-white' ?>">
                            <span class="<?= $on ? 'text-indigo-300' : 'text-slate-500 group-hover:text-slate-300' ?>"><?= icon($ic, 'w-[18px] h-[18px]') ?></span>
                            <?= e($label) ?>
                        </a>
                    </li>
                <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
    </nav>
    <div class="border-t border-white/10 p-4 text-[11px] text-slate-500">
        v<?= e(\App\Core\App::version()) ?> · <?= e((string) setting('app.company', 'Nexta Web Solution')) ?>
    </div>
</aside>

<div class="lg:pl-72 min-h-full flex flex-col">
    <!-- Top bar -->
    <header class="sticky top-0 z-30 flex h-16 items-center gap-3 border-b border-slate-200 bg-white/90 px-4 backdrop-blur sm:px-6">
        <button class="-ml-1 rounded-lg p-2 text-slate-600 hover:bg-slate-100 lg:hidden" @click="nav = true" aria-label="Open menu"><?= icon('menu') ?></button>
        <h1 class="truncate text-base font-semibold text-slate-900 sm:text-lg"><?= e($title ?? '') ?></h1>
        <div class="ml-auto flex items-center gap-2">
            <?php if (can('jobs.create')): ?>
                <a href="<?= e(url('jobs/create')) ?>" class="hidden sm:inline-flex btn-primary"><?= icon('plus', 'w-4 h-4') ?> New search</a>
            <?php endif; ?>
            <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                <button @click="open = !open" class="flex items-center gap-2 rounded-full p-1 pr-2 hover:bg-slate-100" aria-haspopup="true" :aria-expanded="open">
                    <span class="grid h-8 w-8 place-items-center rounded-full bg-indigo-600 text-xs font-semibold text-white"><?= e($initials) ?></span>
                    <span class="hidden md:block text-sm font-medium text-slate-700 max-w-[10rem] truncate"><?= e($user['name'] ?? '') ?></span>
                    <?= icon('chevron-down', 'hidden md:block w-4 h-4 text-slate-400') ?>
                </button>
                <div x-show="open" x-transition.origin.top.right x-cloak class="absolute right-0 mt-2 w-56 rounded-xl border border-slate-200 bg-white p-1 shadow-xl">
                    <div class="px-3 py-2 border-b border-slate-100 mb-1">
                        <div class="text-sm font-medium text-slate-900 truncate"><?= e($user['name'] ?? '') ?></div>
                        <div class="text-xs text-slate-500 truncate"><?= e($user['email'] ?? '') ?> · <?= e($user['role_name'] ?? '') ?></div>
                    </div>
                    <a href="<?= e(url('profile')) ?>" class="menu-item"><?= icon('user', 'w-4 h-4') ?> My profile</a>
                    <?php if (can('apikeys.manage')): ?><a href="<?= e(url('api-keys')) ?>" class="menu-item"><?= icon('key', 'w-4 h-4') ?> API keys</a><?php endif; ?>
                    <form method="post" action="<?= e(url('logout')) ?>">
                        <?= csrf_field() ?>
                        <button class="menu-item w-full text-rose-600"><?= icon('logout', 'w-4 h-4') ?> Sign out</button>
                    </form>
                </div>
            </div>
        </div>
    </header>

    <?php if ($maint): ?>
        <div class="bg-amber-500 px-4 py-2 text-center text-sm font-medium text-white">Maintenance mode is on — only administrators can use the app. <a class="underline" href="<?= e(url('system')) ?>">Manage</a></div>
    <?php endif; ?>

    <main class="flex-1 px-4 py-6 sm:px-6 lg:px-8">
        <?= \App\Core\View::partial('partials/flash') ?>
        <?= $content ?>
    </main>
    <footer class="px-4 pb-6 text-center text-xs text-slate-400 sm:px-6">
        <?= e($appName) ?> · City presets © GeoNames (CC BY 4.0)
    </footer>
</div>
</body>
</html>
