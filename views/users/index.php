<div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
    <form method="get" class="relative sm:w-72">
        <span class="pointer-events-none absolute inset-y-0 left-3 grid place-items-center text-slate-400"><?= icon('search', 'w-4 h-4') ?></span>
        <input name="q" value="<?= e($q) ?>" placeholder="Search users…" class="input pl-9">
    </form>
    <a href="<?= e(url('users/create')) ?>" class="btn-primary"><?= icon('plus', 'w-4 h-4') ?> Add user</a>
</div>
<div class="card mt-4 overflow-hidden">
    <ul class="divide-y divide-slate-100">
    <?php foreach ($users as $u): ?>
        <li class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:px-5">
            <div class="flex min-w-0 flex-1 items-center gap-3">
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-indigo-100 text-sm font-semibold text-indigo-700"><?= e(strtoupper(mb_substr($u['name'], 0, 1))) ?></span>
                <div class="min-w-0">
                    <div class="truncate font-medium text-slate-900"><?= e($u['name']) ?></div>
                    <div class="truncate text-sm text-slate-500"><?= e($u['email']) ?></div>
                </div>
            </div>
            <div class="flex items-center gap-3 text-sm">
                <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-700"><?= e($u['role_name']) ?></span>
                <?= status_badge($u['status']) ?>
                <span class="hidden text-xs text-slate-500 md:inline">Last login <?= e(time_ago($u['last_login_at'])) ?></span>
                <a href="<?= e(url('users/' . $u['id'] . '/edit')) ?>" class="btn-secondary py-1.5"><?= icon('edit', 'w-4 h-4') ?> Edit</a>
            </div>
        </li>
    <?php endforeach; ?>
    </ul>
</div>
<?= \App\Core\View::partial('partials/pagination', ['pg' => $pg]) ?>
