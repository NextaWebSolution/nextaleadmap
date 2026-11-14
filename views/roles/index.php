<div class="flex justify-end"><a href="<?= e(url('roles/create')) ?>" class="btn-primary"><?= icon('plus', 'w-4 h-4') ?> New role</a></div>
<div class="mt-4 grid gap-4 md:grid-cols-2">
<?php foreach ($roles as $r): $perms = json_decode((string) $r['permissions'], true) ?: []; ?>
    <div class="card p-5">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h3 class="font-semibold text-slate-900"><?= e($r['name']) ?> <?= $r['is_system'] ? '<span class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-medium text-slate-500">built-in</span>' : '' ?></h3>
                <p class="text-sm text-slate-500"><?= e($r['description'] ?? '') ?></p>
            </div>
            <a href="<?= e(url('roles/' . $r['id'] . '/edit')) ?>" class="btn-secondary py-1.5"><?= icon('edit', 'w-4 h-4') ?></a>
        </div>
        <div class="mt-3 text-xs text-slate-500"><?= (int) $r['users_count'] ?> user(s)</div>
        <div class="mt-3 flex flex-wrap gap-1.5">
            <?php if (in_array('*', $perms, true)): ?><span class="chip pointer-events-none bg-indigo-50 text-indigo-700">All permissions</span>
            <?php else: foreach ($perms as $p): ?><span class="chip pointer-events-none"><?= e($defs[$p] ?? $p) ?></span><?php endforeach; endif; ?>
        </div>
    </div>
<?php endforeach; ?>
</div>
