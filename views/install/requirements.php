<h1 class="text-lg font-semibold text-slate-900">Server requirements</h1>
<p class="mt-1 text-sm text-slate-500">NextaLeadMap needs PHP 8.3+, MySQL 8 / MariaDB 10.6+ and a few common extensions.</p>
<ul class="mt-5 divide-y divide-slate-100 rounded-xl border border-slate-200">
    <?php foreach ($checks as $c): ?>
        <li class="flex items-start gap-3 p-3 text-sm">
            <span class="mt-0.5 <?= $c['ok'] ? 'text-emerald-600' : ($c['required'] ? 'text-rose-600' : 'text-amber-500') ?>"><?= icon($c['ok'] ? 'check-circle' : 'alert', 'w-4 h-4') ?></span>
            <div class="flex-1"><div class="font-medium text-slate-800"><?= e($c['label']) ?><?= $c['required'] ? '' : ' <span class="text-xs font-normal text-slate-400">(optional)</span>' ?></div><div class="text-xs text-slate-500"><?= e($c['hint']) ?></div></div>
        </li>
    <?php endforeach; ?>
</ul>
<div class="mt-6 flex justify-end">
    <?php if ($ok): ?><a href="<?= e(url('install/database')) ?>" class="btn-primary">Continue</a>
    <?php else: ?><a href="<?= e(url('install')) ?>" class="btn-secondary"><?= icon('refresh', 'w-4 h-4') ?> Re-check</a><?php endif; ?>
</div>
