<form method="get" class="card grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-5">
    <input name="action" value="<?= e($filters['action']) ?>" placeholder="Action contains… (e.g. login)" class="input lg:col-span-2">
    <select name="user" class="input"><option value="">All users</option><?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>" <?= $filters['user'] === (string) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?></select>
    <div class="flex gap-2 lg:col-span-2"><input type="date" name="from" value="<?= e($filters['from']) ?>" class="input" aria-label="From"><input type="date" name="to" value="<?= e($filters['to']) ?>" class="input" aria-label="To"><button class="btn-primary">Filter</button></div>
</form>
<div class="card mt-4 overflow-hidden">
    <ul class="divide-y divide-slate-100">
    <?php foreach ($rows as $a): $danger = preg_match('/failed|forbidden|csrf|throttled|deleted|revoked/', $a['action']); ?>
        <li class="flex flex-col gap-1 px-4 py-3 sm:flex-row sm:items-start sm:gap-4 sm:px-5" x-data="{ open: false }">
            <div class="w-40 shrink-0 text-xs text-slate-500"><?= e(fmt_date($a['created_at'], 'd M Y, H:i:s')) ?></div>
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <code class="rounded px-1.5 py-0.5 font-mono text-xs <?= $danger ? 'bg-rose-50 text-rose-700' : 'bg-slate-100 text-slate-700' ?>"><?= e($a['action']) ?></code>
                    <?php if ($a['entity_type']): ?><span class="text-xs text-slate-500"><?= e($a['entity_type']) ?> <?= e($a['entity_id'] ?? '') ?></span><?php endif; ?>
                    <span class="rounded bg-slate-50 px-1.5 text-[11px] uppercase text-slate-400"><?= e($a['via']) ?></span>
                </div>
                <div class="mt-0.5 text-xs text-slate-500"><?= e($a['user_name'] ?? 'System / guest') ?> · <?= e($a['ip'] ?? '') ?></div>
                <?php if ($a['meta']): ?>
                    <button type="button" class="mt-1 text-xs text-indigo-600" @click="open = !open" x-text="open ? 'Hide details' : 'Details'">Details</button>
                    <pre x-show="open" x-cloak class="mt-1 overflow-x-auto rounded-lg bg-slate-50 p-2 text-[11px] text-slate-700"><?= e(json_encode(json_decode((string) $a['meta'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
                <?php endif; ?>
            </div>
        </li>
    <?php endforeach; ?>
    <?php if (!$rows): ?><li class="px-5 py-10 text-center text-sm text-slate-500">No entries.</li><?php endif; ?>
    </ul>
</div>
<?= \App\Core\View::partial('partials/pagination', ['pg' => $pg]) ?>
