<?php
$t = $totals + ['total' => 0, 'with_email' => 0, 'with_phone' => 0, 'with_website' => 0, 'won' => 0, 'avg_rating' => null];
$total = (int) $t['total'];
$pct = static fn ($n) => $total > 0 ? round(100 * (int) $n / $total) : 0;
$active = (int) ($byStatus['running'] ?? 0) + (int) ($byStatus['enriching'] ?? 0) + (int) ($byStatus['pending'] ?? 0);
$cards = [
    ['Total leads', number_format($total), 'All places collected', 'target', 'from-indigo-500 to-violet-500'],
    ['With email', number_format((int) $t['with_email']), $pct($t['with_email']) . '% of leads', 'mail', 'from-emerald-500 to-teal-500'],
    ['With phone', number_format((int) $t['with_phone']), $pct($t['with_phone']) . '% of leads', 'phone', 'from-sky-500 to-cyan-500'],
    ['Active jobs', number_format($active), number_format((int) ($byStatus['done'] ?? 0)) . ' finished', 'activity', 'from-amber-500 to-orange-500'],
];
?>
<?php if (!$providersReady && can('providers.manage')): ?>
    <div class="mb-6 flex flex-col gap-3 rounded-2xl border border-indigo-200 bg-indigo-50 p-4 sm:flex-row sm:items-center">
        <div class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-indigo-600 text-white"><?= icon('key') ?></div>
        <div class="flex-1 text-sm"><b class="text-indigo-900">Connect a data provider to start.</b> <span class="text-indigo-800">Add a Google Places or SerpApi key — it is stored encrypted.</span></div>
        <a href="<?= e(url('settings/providers')) ?>" class="btn-primary">Add API key</a>
    </div>
<?php endif; ?>
<?php if (!$workerSeen && can('system.manage')): ?>
    <div class="mb-6 flex flex-col gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-4 sm:flex-row sm:items-center">
        <div class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-amber-500 text-white"><?= icon('clock') ?></div>
        <div class="flex-1 text-sm text-amber-900"><b>The background worker has not run yet.</b> Add the cron job shown on the System page so searches get processed.</div>
        <a href="<?= e(url('system')) ?>" class="btn-secondary">Set up cron</a>
    </div>
<?php endif; ?>

<div class="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
    <?php foreach ($cards as [$label, $value, $sub, $ic, $grad]): ?>
        <div class="card p-4 sm:p-5">
            <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                    <p class="truncate text-xs font-medium text-slate-500 sm:text-sm"><?= e($label) ?></p>
                    <p class="mt-1 text-xl font-bold tracking-tight text-slate-900 sm:text-3xl"><?= e($value) ?></p>
                </div>
                <div class="hidden h-10 w-10 shrink-0 place-items-center rounded-xl bg-gradient-to-br <?= $grad ?> text-white shadow-sm sm:grid"><?= icon($ic) ?></div>
            </div>
            <p class="mt-2 truncate text-xs text-slate-500"><?= e($sub) ?></p>
        </div>
    <?php endforeach; ?>
</div>

<div class="mt-6 grid gap-6 xl:grid-cols-3">
    <div class="card p-5 xl:col-span-2">
        <div class="flex items-center justify-between">
            <h2 class="section-title">Leads collected · last 30 days</h2>
        </div>
        <div class="mt-4 h-64"><canvas id="chart-leads" aria-label="Leads per day chart" role="img"></canvas></div>
    </div>
    <div class="card p-5">
        <h2 class="section-title">Top categories</h2>
        <div class="mt-4 h-64"><canvas id="chart-categories" aria-label="Top categories chart" role="img"></canvas></div>
    </div>
</div>

<div class="mt-6 grid items-start gap-6 xl:grid-cols-3">
    <div class="card xl:col-span-2">
        <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
            <h2 class="section-title">Recent searches</h2>
            <a href="<?= e(url('jobs')) ?>" class="text-sm font-medium text-indigo-600 hover:underline">View all</a>
        </div>
        <?php if (!$recent): ?>
            <div class="px-5 py-12 text-center">
                <div class="mx-auto grid h-12 w-12 place-items-center rounded-2xl bg-slate-100 text-slate-400"><?= icon('search', 'w-6 h-6') ?></div>
                <p class="mt-3 text-sm text-slate-600">No searches yet.</p>
                <?php if (can('jobs.create')): ?><a href="<?= e(url('jobs/create')) ?>" class="btn-primary mt-4">Start your first search</a><?php endif; ?>
            </div>
        <?php else: ?>
            <ul class="divide-y divide-slate-100">
                <?php foreach ($recent as $j): ?>
                    <li>
                        <a href="<?= e(url('jobs/' . $j['id'])) ?>" class="flex items-center gap-4 px-5 py-3 hover:bg-slate-50">
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-sm font-medium text-slate-900"><?= e($j['name']) ?></div>
                                <div class="truncate text-xs text-slate-500"><?= e($j['params']['location']['label'] ?? 'No location') ?> · <?= e(time_ago($j['created_at'])) ?></div>
                            </div>
                            <div class="hidden text-right sm:block">
                                <div class="text-sm font-semibold text-slate-900"><?= number_format((int) $j['places_found']) ?></div>
                                <div class="text-xs text-slate-500">places</div>
                            </div>
                            <?= status_badge($j['status']) ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
    <div class="card p-5">
        <h2 class="section-title">Pipeline</h2>
        <?php $st = $chart['statuses']; $sum = max(1, array_sum(array_map('intval', $st))); ?>
        <ul class="mt-4 space-y-3">
            <?php foreach (['new' => 'bg-slate-400', 'contacted' => 'bg-sky-500', 'qualified' => 'bg-violet-500', 'won' => 'bg-emerald-500', 'lost' => 'bg-rose-400'] as $s => $c): $n = (int) ($st[$s] ?? 0); ?>
                <li>
                    <div class="flex justify-between text-sm"><a class="text-slate-700 hover:text-indigo-600" href="<?= e(url('leads', ['status' => $s])) ?>"><?= e(ucfirst($s)) ?></a><span class="font-medium text-slate-900"><?= number_format($n) ?></span></div>
                    <div class="mt-1 h-2 rounded-full bg-slate-100"><div class="h-2 rounded-full <?= $c ?>" style="width: <?= round(100 * $n / $sum, 1) ?>%"></div></div>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="mt-5 text-xs text-slate-500">Average rating of leads: <b class="text-slate-700"><?= $t['avg_rating'] ? number_format((float) $t['avg_rating'], 2) : '—' ?></b></p>
    </div>
</div>

<script type="application/json" id="dashboard-data"><?= js($chart) ?></script>
<?= vendor_script('chart') ?>
