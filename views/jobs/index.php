<div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
    <form method="get" class="flex flex-1 flex-col gap-2 sm:flex-row sm:items-center">
        <div class="relative flex-1 sm:max-w-xs">
            <span class="pointer-events-none absolute inset-y-0 left-3 grid place-items-center text-slate-400"><?= icon('search', 'w-4 h-4') ?></span>
            <input name="q" value="<?= e($filters['q']) ?>" placeholder="Search jobs…" class="input pl-9">
        </div>
        <select name="status" class="input sm:w-44" data-autosubmit>
            <option value="">All statuses</option>
            <?php foreach (['pending', 'running', 'enriching', 'done', 'failed', 'cancelled'] as $s): ?>
                <option value="<?= $s ?>" <?= $filters['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
            <?php endforeach; ?>
        </select>
    </form>
    <?php if (can('jobs.create')): ?><a href="<?= e(url('jobs/create')) ?>" class="btn-primary"><?= icon('plus', 'w-4 h-4') ?> New search</a><?php endif; ?>
</div>

<div class="card mt-4 overflow-hidden" data-live-jobs>
    <?php if (!$jobs): ?>
        <div class="px-6 py-16 text-center">
            <div class="mx-auto grid h-12 w-12 place-items-center rounded-2xl bg-slate-100 text-slate-400"><?= icon('briefcase', 'w-6 h-6') ?></div>
            <p class="mt-3 text-sm text-slate-600">No jobs match.</p>
        </div>
    <?php else: ?>
        <table class="table-auto hidden w-full md:table">
            <thead class="thead">
                <tr><th class="th">Job</th><th class="th">Location</th><th class="th">Progress</th><th class="th text-right">Places</th><th class="th text-right">Emails</th><th class="th">Created</th><th class="th"></th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
            <?php foreach ($jobs as $j): $pct = (int) ($j['progress']['percent'] ?? 0); ?>
                <tr class="hover:bg-slate-50">
                    <td class="td max-w-xs">
                        <a href="<?= e(url('jobs/' . $j['id'])) ?>" class="block truncate font-medium text-slate-900 hover:text-indigo-600"><?= e($j['name']) ?></a>
                        <div class="truncate text-xs text-slate-500"><?= e(implode(', ', array_slice($j['params']['keywords'] ?? [], 0, 4))) ?><?= count($j['params']['keywords'] ?? []) > 4 ? '…' : '' ?></div>
                    </td>
                    <td class="td max-w-[14rem] truncate text-slate-600"><?= e(str_limit($j['params']['location']['label'] ?? '—', 40)) ?></td>
                    <td class="td w-48">
                        <div class="flex items-center gap-2"><?= status_badge($j['status']) ?><span class="text-xs text-slate-500"><?= $pct ?>%</span></div>
                        <div class="mt-1.5 h-1.5 rounded-full bg-slate-100"><div class="h-1.5 rounded-full bg-indigo-500" style="width: <?= $pct ?>%"></div></div>
                    </td>
                    <td class="td text-right font-semibold"><?= number_format((int) $j['places_found']) ?></td>
                    <td class="td text-right"><?= number_format((int) $j['emails_found']) ?></td>
                    <td class="td whitespace-nowrap text-slate-500"><?= e(time_ago($j['created_at'])) ?><?= can('jobs.view_all') && $j['user_name'] ? '<div class="text-xs">' . e($j['user_name']) . '</div>' : '' ?></td>
                    <td class="td text-right"><a href="<?= e(url('jobs/' . $j['id'])) ?>" class="text-slate-400 hover:text-indigo-600"><?= icon('chevron-right', 'w-5 h-5') ?></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <ul class="divide-y divide-slate-100 md:hidden">
            <?php foreach ($jobs as $j): $pct = (int) ($j['progress']['percent'] ?? 0); ?>
                <li>
                    <a href="<?= e(url('jobs/' . $j['id'])) ?>" class="block p-4 active:bg-slate-50">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="truncate font-medium text-slate-900"><?= e($j['name']) ?></div>
                                <div class="truncate text-xs text-slate-500"><?= e($j['params']['location']['label'] ?? 'No location') ?></div>
                            </div>
                            <?= status_badge($j['status']) ?>
                        </div>
                        <div class="mt-3 h-1.5 rounded-full bg-slate-100"><div class="h-1.5 rounded-full bg-indigo-500" style="width: <?= $pct ?>%"></div></div>
                        <div class="mt-2 flex gap-4 text-xs text-slate-600">
                            <span><b class="text-slate-900"><?= number_format((int) $j['places_found']) ?></b> places</span>
                            <span><b class="text-slate-900"><?= number_format((int) $j['emails_found']) ?></b> emails</span>
                            <span class="ml-auto text-slate-400"><?= e(time_ago($j['created_at'])) ?></span>
                        </div>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
<?= \App\Core\View::partial('partials/pagination', ['pg' => $pg]) ?>
