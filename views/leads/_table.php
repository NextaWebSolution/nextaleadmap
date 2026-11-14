<?php
/** @var array $leads @var bool $selectable @var bool $showJob */
$socialIcons = ['instagram' => 'instagram', 'facebook' => 'facebook', 'linkedin' => 'linkedin', 'tiktok' => 'music', 'youtube' => 'youtube', 'x' => 'twitter'];
$canUpdate = can('leads.update');
$safeUrl = static function (?string $u): string {
    $u = trim((string) $u);
    if ($u === '') {
        return '';
    }
    if (!preg_match('#^https?://#i', $u)) {
        $u = 'http://' . $u;
    }
    return filter_var($u, FILTER_VALIDATE_URL) ? $u : '';
};
$host = static fn (?string $u) => preg_replace('#^(https?://)?(www\.)?#i', '', rtrim((string) $u, '/'));
if (!$leads): ?>
    <div class="px-6 py-14 text-center">
        <div class="mx-auto grid h-12 w-12 place-items-center rounded-2xl bg-slate-100 text-slate-400"><?= icon('target', 'w-6 h-6') ?></div>
        <p class="mt-3 text-sm text-slate-600">No leads here yet.</p>
    </div>
<?php return; endif; ?>

<div class="hidden overflow-x-auto lg:block">
<table class="w-full">
    <thead class="thead"><tr>
        <?php if ($selectable): ?><th class="th w-10"><input type="checkbox" class="cb" data-select-all aria-label="Select all"></th><?php endif; ?>
        <th class="th">Business</th><th class="th">Contact</th><th class="th">Socials</th><th class="th text-right">Rating</th><th class="th">Status</th><th class="th"></th>
    </tr></thead>
    <tbody class="divide-y divide-slate-100">
    <?php foreach ($leads as $l): $web = $safeUrl($l['website']); ?>
        <tr class="hover:bg-slate-50 align-top">
            <?php if ($selectable): ?><td class="td"><input type="checkbox" class="cb" name="ids[]" value="<?= (int) $l['id'] ?>" form="bulk-form" data-select-item aria-label="Select"></td><?php endif; ?>
            <td class="td max-w-xs">
                <a href="<?= e(url('leads/' . $l['id'])) ?>" class="block truncate font-medium text-slate-900 hover:text-indigo-600"><?= e($l['title']) ?></a>
                <div class="truncate text-xs text-slate-500"><?= e($l['category'] ?? '') ?></div>
                <div class="truncate text-xs text-slate-400"><?= e($l['address'] ?? '') ?></div>
                <?php if ($showJob): ?><div class="mt-1 truncate text-[11px] text-indigo-500"><?= e($l['job_name']) ?></div><?php endif; ?>
            </td>
            <td class="td max-w-[16rem] space-y-0.5 text-xs">
                <?php if ($l['phone']): ?><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $l['phone'])) ?>" class="flex items-center gap-1.5 text-slate-700 hover:text-indigo-600"><?= icon('phone', 'w-3.5 h-3.5 text-slate-400') ?><?= e($l['phone']) ?></a><?php endif; ?>
                <?php foreach (array_filter(explode(', ', (string) $l['emails'])) as $em): ?>
                    <a href="mailto:<?= e($em) ?>" class="flex items-center gap-1.5 truncate text-slate-700 hover:text-indigo-600"><?= icon('mail', 'w-3.5 h-3.5 text-slate-400') ?><span class="truncate"><?= e($em) ?></span></a>
                <?php endforeach; ?>
                <?php if ($web): ?><a href="<?= e($web) ?>" target="_blank" rel="noopener noreferrer nofollow" class="flex items-center gap-1.5 truncate text-slate-700 hover:text-indigo-600"><?= icon('globe', 'w-3.5 h-3.5 text-slate-400') ?><span class="truncate"><?= e($host($l['website'])) ?></span></a>
                <?php else: ?><span class="inline-flex rounded bg-amber-50 px-1.5 py-0.5 text-[11px] font-medium text-amber-700">No website</span><?php endif; ?>
            </td>
            <td class="td">
                <div class="flex flex-wrap gap-1">
                <?php foreach ($socialIcons as $net => $ic): $u = $safeUrl($l[$net] ?? ''); if ($u): ?>
                    <a href="<?= e($u) ?>" target="_blank" rel="noopener noreferrer nofollow" class="grid h-7 w-7 place-items-center rounded-lg bg-slate-100 text-slate-500 hover:bg-indigo-100 hover:text-indigo-600" title="<?= e(ucfirst($net)) ?>"><?= icon($ic, 'w-3.5 h-3.5') ?></a>
                <?php endif; endforeach; ?>
                </div>
            </td>
            <td class="td text-right whitespace-nowrap">
                <?php if ($l['rating'] !== null): ?><span class="inline-flex items-center gap-1 font-semibold text-slate-900"><?= icon('star', 'w-3.5 h-3.5 text-amber-400') ?><?= e(number_format((float) $l['rating'], 1)) ?></span><div class="text-xs text-slate-500"><?= number_format((int) $l['review_count']) ?> reviews</div><?php else: ?>—<?php endif; ?>
                <?php if ($l['intent_score'] !== null): ?><div class="mt-1 text-[11px] font-medium text-violet-600">Intent <?= (int) $l['intent_score'] ?></div><?php endif; ?>
            </td>
            <td class="td">
                <?php if ($canUpdate): ?>
                    <select class="input input-sm w-32" data-lead-status="<?= e(url('leads/' . $l['id'])) ?>" aria-label="Lead status">
                        <?php foreach (\App\Repositories\PlaceRepository::LEAD_STATUSES as $s): ?><option value="<?= $s ?>" <?= $l['lead_status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?>
                    </select>
                <?php else: ?><?= status_badge($l['lead_status']) ?><?php endif; ?>
            </td>
            <td class="td text-right"><a href="<?= e(url('leads/' . $l['id'])) ?>" class="text-slate-400 hover:text-indigo-600"><?= icon('chevron-right', 'w-5 h-5') ?></a></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<ul class="divide-y divide-slate-100 lg:hidden">
<?php foreach ($leads as $l): $web = $safeUrl($l['website']); ?>
    <li class="p-4">
        <div class="flex items-start gap-3">
            <?php if ($selectable): ?><input type="checkbox" class="cb mt-1" name="ids[]" value="<?= (int) $l['id'] ?>" form="bulk-form" data-select-item aria-label="Select"><?php endif; ?>
            <div class="min-w-0 flex-1">
                <div class="flex items-start justify-between gap-2">
                    <a href="<?= e(url('leads/' . $l['id'])) ?>" class="font-medium text-slate-900"><?= e($l['title']) ?></a>
                    <?php if ($l['rating'] !== null): ?><span class="inline-flex shrink-0 items-center gap-1 text-sm font-semibold"><?= icon('star', 'w-3.5 h-3.5 text-amber-400') ?><?= e(number_format((float) $l['rating'], 1)) ?><span class="text-xs font-normal text-slate-500">(<?= (int) $l['review_count'] ?>)</span></span><?php endif; ?>
                </div>
                <div class="text-xs text-slate-500"><?= e($l['category'] ?? '') ?></div>
                <div class="mt-2 flex flex-wrap gap-2">
                    <?php if ($l['phone']): ?><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $l['phone'])) ?>" class="pill"><?= icon('phone', 'w-3.5 h-3.5') ?>Call</a><?php endif; ?>
                    <?php $em = explode(', ', (string) $l['emails'])[0] ?? ''; if ($em): ?><a href="mailto:<?= e($em) ?>" class="pill"><?= icon('mail', 'w-3.5 h-3.5') ?>Email</a><?php endif; ?>
                    <?php if ($web): ?><a href="<?= e($web) ?>" target="_blank" rel="noopener noreferrer nofollow" class="pill"><?= icon('globe', 'w-3.5 h-3.5') ?>Website</a><?php else: ?><span class="pill bg-amber-50 text-amber-700">No website</span><?php endif; ?>
                    <?php foreach ($socialIcons as $net => $ic): $u = $safeUrl($l[$net] ?? ''); if ($u): ?><a href="<?= e($u) ?>" target="_blank" rel="noopener noreferrer nofollow" class="pill px-2" title="<?= e(ucfirst($net)) ?>"><?= icon($ic, 'w-3.5 h-3.5') ?></a><?php endif; endforeach; ?>
                </div>
                <?php if ($canUpdate): ?>
                    <select class="input input-sm mt-3 w-full" data-lead-status="<?= e(url('leads/' . $l['id'])) ?>" aria-label="Lead status">
                        <?php foreach (\App\Repositories\PlaceRepository::LEAD_STATUSES as $s): ?><option value="<?= $s ?>" <?= $l['lead_status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </div>
        </div>
    </li>
<?php endforeach; ?>
</ul>
