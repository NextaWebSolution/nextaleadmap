<?php
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
$l = $lead;
$labels = \App\Services\Enrichment\TechDetector::labels();
?>
<div class="mb-4"><a href="<?= e(url('jobs/' . $l['job_id'])) ?>" class="text-sm text-slate-500 hover:text-indigo-600">← <?= e($l['job_name']) ?></a></div>
<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
        <div class="card p-5 sm:p-6">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0">
                    <h2 class="text-xl font-bold text-slate-900 break-words"><?= e($l['title']) ?></h2>
                    <p class="text-sm text-slate-500"><?= e($l['category'] ?? '') ?></p>
                </div>
                <?php if ($l['rating'] !== null): ?>
                    <div class="flex items-center gap-2 rounded-xl bg-amber-50 px-3 py-2 text-amber-800"><?= icon('star', 'w-5 h-5 text-amber-400') ?><b class="text-lg"><?= e(number_format((float) $l['rating'], 1)) ?></b><span class="text-sm"><?= number_format((int) $l['review_count']) ?> reviews</span></div>
                <?php endif; ?>
            </div>
            <dl class="mt-6 grid gap-4 sm:grid-cols-2">
                <?php
                $rows = [
                    ['Phone', $l['phone'] ? '<a class="link" href="tel:' . e(preg_replace('/[^0-9+]/', '', $l['phone'])) . '">' . e($l['phone']) . '</a>' : '—'],
                    ['Emails', $l['emails'] ? implode('<br>', array_map(static fn ($m) => '<a class="link" href="mailto:' . e($m) . '">' . e($m) . '</a>', explode(', ', $l['emails']))) : '—'],
                    ['Website', ($w = $safeUrl($l['website'])) ? '<a class="link break-all" target="_blank" rel="noopener noreferrer nofollow" href="' . e($w) . '">' . e($l['website']) . '</a>' : '<span class="text-amber-700">No website</span>'],
                    ['Mail host', e($l['email_host'] ?: '—')],
                    ['Address', e($l['address'] ?: '—')],
                    ['Plus code', e($l['plus_code'] ?: '—')],
                    ['Coordinates', $l['latitude'] ? e($l['latitude'] . ', ' . $l['longitude']) : '—'],
                    ['Price level', e($l['price_level'] ?: '—')],
                    ['Business status', e($l['business_status'] ? ucfirst(str_replace('_', ' ', $l['business_status'])) : '—')],
                    ['Website check', e($l['website_status'] ?: '—')],
                ];
                foreach ($rows as [$k, $v]): ?>
                    <div><dt class="text-xs font-medium uppercase tracking-wide text-slate-500"><?= e($k) ?></dt><dd class="mt-1 text-sm text-slate-800 break-words"><?= $v ?></dd></div>
                <?php endforeach; ?>
            </dl>
            <?php if ($l['opening_hours']): ?>
                <div class="mt-5"><div class="text-xs font-medium uppercase tracking-wide text-slate-500">Opening hours</div>
                    <ul class="mt-1 grid gap-0.5 text-sm text-slate-700 sm:grid-cols-2"><?php foreach (explode('; ', $l['opening_hours']) as $h): ?><li><?= e($h) ?></li><?php endforeach; ?></ul></div>
            <?php endif; ?>
            <div class="mt-6 flex flex-wrap gap-2">
                <?php if ($m = $safeUrl($l['maps_url'])): ?><a class="btn-secondary" target="_blank" rel="noopener noreferrer" href="<?= e($m) ?>"><?= icon('map-pin', 'w-4 h-4') ?> Open in Google Maps</a><?php endif; ?>
                <?php foreach (['instagram' => 'instagram', 'facebook' => 'facebook', 'linkedin' => 'linkedin', 'tiktok' => 'music', 'youtube' => 'youtube', 'x' => 'twitter'] as $net => $ic): if ($u = $safeUrl($l[$net])): ?>
                    <a class="btn-secondary" target="_blank" rel="noopener noreferrer nofollow" href="<?= e($u) ?>"><?= icon($ic, 'w-4 h-4') ?> <?= e(ucfirst($net)) ?></a>
                <?php endif; endforeach; ?>
            </div>
        </div>
        <?php if ($tech): ?>
            <div class="card p-5 sm:p-6">
                <div class="flex items-center justify-between"><h3 class="section-title">Marketing &amp; tech signals</h3><?php if ($l['intent_score'] !== null): ?><span class="rounded-full bg-violet-100 px-3 py-1 text-sm font-semibold text-violet-700">Intent score <?= (int) $l['intent_score'] ?>/100</span><?php endif; ?></div>
                <div class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-4">
                    <?php foreach ($labels as $k => $lab): $v = $tech[$k] ?? ''; ?>
                        <div class="rounded-xl border border-slate-200 p-3"><div class="text-xs text-slate-500"><?= e($lab) ?></div><div class="mt-1 text-sm font-semibold <?= $v === 'yes' ? 'text-emerald-600' : 'text-slate-700' ?>"><?= e($v !== '' ? ucfirst($v) : '—') ?></div></div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <div class="space-y-6">
        <form method="post" action="<?= e(url('leads/' . $l['id'])) ?>" class="card p-5">
            <?= csrf_field() ?>
            <h3 class="section-title">Pipeline</h3>
            <label class="label mt-3" for="lead_status">Status</label>
            <select id="lead_status" name="lead_status" class="input" <?= can('leads.update') ? '' : 'disabled' ?>>
                <?php foreach (\App\Repositories\PlaceRepository::LEAD_STATUSES as $s): ?><option value="<?= $s ?>" <?= $l['lead_status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?>
            </select>
            <label class="label mt-4" for="notes">Notes</label>
            <textarea id="notes" name="notes" rows="6" maxlength="5000" class="input" <?= can('leads.update') ? '' : 'disabled' ?> placeholder="Call notes, follow-up date…"><?= e($l['notes'] ?? '') ?></textarea>
            <?php if (can('leads.update')): ?><button class="btn-primary mt-4 w-full justify-center">Save</button><?php endif; ?>
        </form>
        <div class="card p-5 text-sm text-slate-600">
            <div>Found by keyword <b class="text-slate-800"><?= e($l['keyword'] ?? '') ?></b></div>
            <div class="mt-1">Added <?= e(fmt_date($l['created_at'])) ?></div>
            <?php if ($l['enriched_at']): ?><div class="mt-1">Website checked <?= e(fmt_date($l['enriched_at'])) ?></div><?php endif; ?>
        </div>
    </div>
</div>
