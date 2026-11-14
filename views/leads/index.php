<?php
$q = array_filter($filters, static fn ($v) => $v !== '' && $v !== 'newest');
$activeCount = count(array_diff_key($q, ['q' => 1, 'sort' => 1]));
?>
<div x-data="{ filters: <?= $activeCount ? 'true' : 'false' ?> }">
    <form method="get" class="card p-4">
        <div class="flex flex-col gap-2 sm:flex-row">
            <div class="relative flex-1">
                <span class="pointer-events-none absolute inset-y-0 left-3 grid place-items-center text-slate-400"><?= icon('search', 'w-4 h-4') ?></span>
                <input name="q" value="<?= e($filters['q']) ?>" placeholder="Search name, email, phone, website, address…" class="input pl-9">
            </div>
            <div class="flex gap-2">
                <button type="button" class="btn-secondary flex-1 sm:flex-none" @click="filters = !filters"><?= icon('filter', 'w-4 h-4') ?> Filters<?= $activeCount ? ' <span class="rounded-full bg-indigo-600 px-1.5 text-xs text-white">' . $activeCount . '</span>' : '' ?></button>
                <button class="btn-primary flex-1 sm:flex-none">Apply</button>
            </div>
        </div>
        <div x-show="filters" x-cloak x-transition class="mt-4 grid gap-3 border-t border-slate-100 pt-4 sm:grid-cols-2 lg:grid-cols-4">
            <div><label class="label">Job</label><select name="job" class="input"><option value="">All jobs</option><?php foreach ($jobs as $j): ?><option value="<?= e($j['id']) ?>" <?= $filters['job'] === $j['id'] ? 'selected' : '' ?>><?= e(str_limit($j['name'], 40)) ?></option><?php endforeach; ?></select></div>
            <div><label class="label">Status</label><select name="status" class="input"><option value="">Any</option><?php foreach (\App\Repositories\PlaceRepository::LEAD_STATUSES as $s): ?><option value="<?= $s ?>" <?= $filters['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select></div>
            <?php foreach (['has_email' => 'Email', 'has_phone' => 'Phone', 'has_website' => 'Website'] as $k => $l): ?>
                <div><label class="label"><?= $l ?></label><select name="<?= $k ?>" class="input"><option value="">Any</option><option value="yes" <?= $filters[$k] === 'yes' ? 'selected' : '' ?>>Has <?= strtolower($l) ?></option><option value="no" <?= $filters[$k] === 'no' ? 'selected' : '' ?>>No <?= strtolower($l) ?></option></select></div>
            <?php endforeach; ?>
            <div><label class="label">Min rating</label><select name="min_rating" class="input"><option value="">Any</option><?php foreach (['3', '3.5', '4', '4.5'] as $r): ?><option value="<?= $r ?>" <?= $filters['min_rating'] === $r ? 'selected' : '' ?>><?= $r ?>+</option><?php endforeach; ?></select></div>
            <div><label class="label">Min reviews</label><input name="min_reviews" type="number" min="0" class="input" value="<?= e($filters['min_reviews']) ?>"></div>
            <div><label class="label">Sort</label><select name="sort" class="input"><?php foreach (['newest' => 'Newest', 'rating' => 'Top rated', 'reviews' => 'Most reviews', 'score' => 'Intent score', 'name' => 'Name A–Z'] as $k => $l): ?><option value="<?= $k ?>" <?= $filters['sort'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
            <div class="sm:col-span-2 lg:col-span-4 flex justify-end"><a href="<?= e(url('leads')) ?>" class="text-sm text-slate-500 hover:text-slate-800">Reset filters</a></div>
        </div>
    </form>

    <div class="mt-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <p class="text-sm text-slate-600"><b class="text-slate-900"><?= number_format((int) $pg['total']) ?></b> leads</p>
        <div class="flex flex-wrap gap-2">
            <?php if (can('leads.update')): ?>
                <form id="bulk-form" method="post" action="<?= e(url('leads/bulk')) ?>" class="flex gap-2" data-confirm-delete="Delete the selected leads permanently?">
                    <?= csrf_field() ?>
                    <select name="action" class="input input-sm w-40" aria-label="Bulk action">
                        <option value="">Bulk action…</option>
                        <?php foreach (\App\Repositories\PlaceRepository::LEAD_STATUSES as $s): ?><option value="<?= $s ?>">Mark <?= $s ?></option><?php endforeach; ?>
                        <?php if (can('leads.delete')): ?><option value="delete">Delete</option><?php endif; ?>
                    </select>
                    <button class="btn-secondary py-1.5">Apply</button>
                </form>
            <?php endif; ?>
            <?php if (can('leads.export')): $eq = $q; ?>
                <a class="btn-secondary py-1.5" href="<?= e(url('leads/export', $eq + ['format' => 'leads'])) ?>"><?= icon('download', 'w-4 h-4') ?> CSV</a>
                <a class="btn-secondary py-1.5" href="<?= e(url('leads/export', $eq + ['format' => 'full'])) ?>">Full CSV</a>
                <a class="btn-secondary py-1.5" href="<?= e(url('leads/export', $eq + ['format' => 'json'])) ?>">JSON</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="card mt-3 overflow-hidden">
        <?= \App\Core\View::partial('leads/_table', ['leads' => $leads, 'selectable' => can('leads.update'), 'showJob' => true]) ?>
    </div>
    <?= \App\Core\View::partial('partials/pagination', ['pg' => $pg]) ?>
</div>
