<?php $fileBackups = array_values(array_filter($backups, static fn ($b) => str_starts_with($b['name'], 'files-'))); ?>
<div class="grid gap-6 xl:grid-cols-3">
    <div class="space-y-6 xl:col-span-2">

        <!-- Updates -->
        <div class="card p-5 sm:p-6">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 class="section-title">Updates</h2>
                    <p class="mt-1 text-sm text-slate-500">Installed: <b class="text-slate-800">v<?= e(\App\Core\App::version()) ?></b><?php if ($lastCheck): ?> · Last check <?= e(time_ago($lastCheck['at'] ?? null)) ?>, latest <b><?= e($lastCheck['latest'] ?? '?') ?></b><?php endif; ?></p>
                </div>
                <form method="post" action="<?= e(url('system/update/check')) ?>"><?= csrf_field() ?><button class="btn-secondary"><?= icon('refresh', 'w-4 h-4') ?> Check now</button></form>
            </div>
            <div class="mt-4 rounded-xl bg-slate-50 p-4 text-sm text-slate-600">
                Releases are verified with the Ed25519 public key in <code class="font-mono text-xs">config/update-public-key.txt</code>, the SHA-256 checksum from the signed manifest, and safe ZIP extraction. Code files are backed up first; <code>.env</code>, <code>storage/</code> and uploads are never touched. A failed update restores itself.
            </div>
            <form method="post" action="<?= e(url('system/update/apply')) ?>" class="mt-4 flex flex-col gap-2 sm:flex-row" data-confirm="Install the latest verified release now? The app goes into maintenance mode for a moment.">
                <?= csrf_field() ?>
                <input type="password" name="confirm_password" required class="input sm:max-w-xs" placeholder="Your password to confirm" autocomplete="current-password">
                <button class="btn-primary"><?= icon('cloud-download', 'w-4 h-4') ?> Install update</button>
            </form>
            <?php if ($history): ?>
                <div class="mt-5 overflow-x-auto">
                    <table class="w-full text-sm"><thead class="thead"><tr><th class="th">When</th><th class="th">From → To</th><th class="th">Status</th><th class="th">By</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                    <?php foreach ($history as $h): ?>
                        <tr x-data="{ open: false }"><td class="td whitespace-nowrap text-slate-500"><?= e(fmt_date($h['created_at'])) ?></td><td class="td font-mono text-xs"><?= e($h['from_version']) ?> → <?= e($h['to_version']) ?></td>
                            <td class="td"><?= status_badge($h['status']) ?> <?php if ($h['log']): ?><button type="button" class="ml-1 text-xs text-indigo-600" @click="open = !open">log</button><pre x-show="open" x-cloak class="mt-2 max-w-md overflow-x-auto whitespace-pre-wrap rounded bg-slate-50 p-2 text-[11px]"><?= e($h['log']) ?></pre><?php endif; ?></td>
                            <td class="td text-slate-500"><?= e($h['user_name'] ?? 'CLI') ?></td></tr>
                    <?php endforeach; ?>
                    </tbody></table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Worker -->
        <div class="card p-5 sm:p-6">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 class="section-title">Background worker</h2>
                    <p class="mt-1 text-sm text-slate-500">Last run: <b class="<?= $workerSeen && strtotime($workerSeen . ' UTC') > time() - 300 ? 'text-emerald-600' : 'text-amber-600' ?>"><?= e($workerSeen ? time_ago($workerSeen) : 'never') ?></b> · <?= (int) $counts['pending'] ?> pending · <?= (int) $counts['running'] ?> running</p>
                </div>
                <form method="post" action="<?= e(url('system/worker')) ?>"><?= csrf_field() ?><button class="btn-secondary"><?= icon('play', 'w-4 h-4') ?> Run queue now (25 s)</button></form>
            </div>
            <div class="mt-4 space-y-4 text-sm">
                <div>
                    <div class="font-medium text-slate-800">cPanel → Cron Jobs → "Once per minute"</div>
                    <div class="mt-2 flex gap-2"><input id="cron-cmd" readonly class="input flex-1 font-mono text-xs" value="<?= e($cronCommand) ?>"><button type="button" class="btn-secondary" data-copy="#cron-cmd"><?= icon('copy', 'w-4 h-4') ?></button></div>
                    <p class="mt-1 text-xs text-slate-500">Check the PHP path in cPanel (often <code>/usr/local/bin/php</code> or <code>/opt/cpanel/ea-php83/root/usr/bin/php</code>).</p>
                </div>
                <?php if ($cronUrl): ?>
                <div x-data="{ show: false }">
                    <div class="font-medium text-slate-800">No CLI cron? Use an HTTP cron service</div>
                    <div class="mt-2 flex gap-2">
                        <input id="cron-url" readonly :type="show ? 'text' : 'password'" type="password" class="input flex-1 font-mono text-xs" value="<?= e($cronUrl) ?>">
                        <button type="button" class="btn-secondary" @click="show = !show"><?= icon('eye', 'w-4 h-4') ?></button>
                        <button type="button" class="btn-secondary" data-copy="#cron-url"><?= icon('copy', 'w-4 h-4') ?></button>
                    </div>
                    <form method="post" action="<?= e(url('system/cron-token')) ?>" class="mt-1" data-confirm="Generate a new token? The old URL stops working."><?= csrf_field() ?><button class="text-xs text-indigo-600 hover:underline">Regenerate token</button></form>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Backups -->
        <div class="card p-5 sm:p-6">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div><h2 class="section-title">Backups</h2><p class="mt-1 text-sm text-slate-500">Stored in <code class="font-mono text-xs">storage/backups/</code> (not web-accessible).</p></div>
                <form method="post" action="<?= e(url('system/backup/db')) ?>"><?= csrf_field() ?><button class="btn-secondary"><?= icon('database', 'w-4 h-4') ?> Back up database</button></form>
            </div>
            <ul class="mt-4 divide-y divide-slate-100 rounded-xl border border-slate-200">
                <?php foreach ($backups as $b): ?>
                    <li class="flex flex-col gap-2 p-3 text-sm sm:flex-row sm:items-center">
                        <div class="min-w-0 flex-1"><div class="truncate font-mono text-xs text-slate-800"><?= e($b['name']) ?></div><div class="text-xs text-slate-500"><?= e(human_bytes($b['size'])) ?> · <?= e(fmt_date($b['time'])) ?></div></div>
                        <div class="flex gap-2">
                            <a href="<?= e(url('system/backups/' . $b['name'])) ?>" class="btn-secondary py-1"><?= icon('download', 'w-4 h-4') ?></a>
                            <form method="post" action="<?= e(url('system/backups/' . $b['name'])) ?>" data-confirm="Delete this backup?"><?= csrf_field() ?><?= method_field('DELETE') ?><button class="btn-secondary py-1 text-rose-600"><?= icon('trash', 'w-4 h-4') ?></button></form>
                        </div>
                    </li>
                <?php endforeach; ?>
                <?php if (!$backups): ?><li class="p-4 text-center text-sm text-slate-500">No backups yet.</li><?php endif; ?>
            </ul>
            <?php if ($fileBackups): ?>
                <form method="post" action="<?= e(url('system/backup/restore')) ?>" class="mt-4 flex flex-col gap-2 sm:flex-row" data-confirm="Restore application files from this backup?">
                    <?= csrf_field() ?>
                    <select name="backup" class="input sm:max-w-xs"><?php foreach ($fileBackups as $b): ?><option value="<?= e($b['name']) ?>"><?= e($b['name']) ?></option><?php endforeach; ?></select>
                    <input type="password" name="confirm_password" required class="input sm:max-w-[12rem]" placeholder="Password" autocomplete="current-password">
                    <button class="btn-secondary"><?= icon('refresh', 'w-4 h-4') ?> Restore files</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="space-y-6">
        <div class="card p-5">
            <h2 class="section-title">Security checklist</h2>
            <ul class="mt-3 space-y-2 text-sm">
                <?php foreach ($checks as [$label, $ok, $fix]): ?>
                    <li class="flex gap-2"><span class="mt-0.5 <?= $ok ? 'text-emerald-600' : 'text-amber-600' ?>"><?= icon($ok ? 'check-circle' : 'alert', 'w-4 h-4') ?></span>
                        <span><span class="<?= $ok ? 'text-slate-700' : 'font-medium text-slate-900' ?>"><?= e($label) ?></span><?php if (!$ok): ?><span class="block text-xs text-slate-500"><?= e($fix) ?></span><?php endif; ?></span></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div class="card p-5">
            <h2 class="section-title">Environment</h2>
            <dl class="mt-3 space-y-2 text-sm">
                <?php foreach ($info as $k => $v): ?><div class="flex justify-between gap-3"><dt class="text-slate-500"><?= e($k) ?></dt><dd class="text-right font-medium text-slate-800 break-all"><?= e($v) ?></dd></div><?php endforeach; ?>
                <div class="flex justify-between"><dt class="text-slate-500">Leads stored</dt><dd class="font-medium"><?= number_format((int) $counts['places']) ?></dd></div>
                <div class="flex justify-between"><dt class="text-slate-500">Users</dt><dd class="font-medium"><?= (int) $counts['users'] ?></dd></div>
            </dl>
        </div>
        <div class="card p-5">
            <h2 class="section-title">Database migrations</h2>
            <?php if ($pendingMigrations): ?>
                <p class="mt-2 text-sm text-amber-700"><?= count($pendingMigrations) ?> pending: <?= e(implode(', ', $pendingMigrations)) ?></p>
                <form method="post" action="<?= e(url('system/migrate')) ?>" class="mt-3"><?= csrf_field() ?><button class="btn-primary">Run migrations</button></form>
            <?php else: ?><p class="mt-2 text-sm text-emerald-700">Database schema is up to date.</p><?php endif; ?>
        </div>
        <div class="card p-5">
            <h2 class="section-title">Maintenance mode</h2>
            <p class="mt-1 text-sm text-slate-500"><?= $maintenance ? 'ON — visitors see a maintenance page.' : 'Off.' ?></p>
            <form method="post" action="<?= e(url('system/maintenance')) ?>" class="mt-3 space-y-2">
                <?= csrf_field() ?>
                <input type="hidden" name="on" value="<?= $maintenance ? '0' : '1' ?>">
                <?php if (!$maintenance): ?><input name="message" class="input" placeholder="Message for visitors" maxlength="200"><?php endif; ?>
                <button class="btn-secondary w-full justify-center"><?= $maintenance ? 'Turn off' : 'Turn on' ?></button>
            </form>
        </div>
    </div>
</div>
