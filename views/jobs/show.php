<?php
$p = $job['params'];
$pr = $job['progress'];
$activeJob = in_array($job['status'], ['pending', 'running', 'enriching'], true);
$init = ['url' => url('jobs/' . $job['id'] . '/status'), 'active' => $activeJob, 'status' => $job['status'], 'percent' => (int) ($pr['percent'] ?? 0), 'message' => (string) ($pr['message'] ?? ''), 'places' => (int) $job['places_found'], 'emails' => (int) $job['emails_found']];
$flags = array_filter(['Emails' => $p['emails'] ?? false, 'Socials' => $p['socials'] ?? false, 'MX verify' => $p['verify_emails'] ?? false, 'Tech signals' => $p['tech'] ?? false, 'No-website only' => $p['only_without_website'] ?? false, 'Deep scan' => $p['deep_scan'] ?? false]);
?>
<script type="application/json" id="job-progress-data"><?= js($init) ?></script>
<div x-data="jobProgress" class="space-y-6">
    <div class="card p-5 sm:p-6">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <span x-html="badge"><?= status_badge($job['status']) ?></span>
                    <span class="text-xs text-slate-500"><?= e(\App\Services\Providers\ProviderFactory::PROVIDERS[$job['provider']][1] ?? $job['provider']) ?></span>
                    <span class="text-xs text-slate-400">· created <?= e(fmt_date($job['created_at'])) ?><?= $job['user_name'] ? ' by ' . e($job['user_name']) : '' ?></span>
                </div>
                <p class="mt-2 text-sm text-slate-600"><b class="text-slate-800">Keywords:</b> <?= e(implode(', ', $p['keywords'] ?? [])) ?></p>
                <p class="mt-1 text-sm text-slate-600"><b class="text-slate-800">Location:</b> <?= e($p['location']['label'] ?: 'From keywords') ?><?= ($p['grid'] ?? 1) > 1 ? ' · ' . (int) $p['grid'] . '×' . (int) $p['grid'] . ' grid' : '' ?></p>
                <?php if ($flags): ?>
                    <div class="mt-3 flex flex-wrap gap-1.5"><?php foreach (array_keys($flags) as $f): ?><span class="chip pointer-events-none"><?= e($f) ?></span><?php endforeach; ?></div>
                <?php endif; ?>
            </div>
            <div class="flex flex-wrap gap-2">
                <?php if (can('leads.export')): ?>
                    <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                        <button type="button" class="btn-primary" @click="open = !open"><?= icon('download', 'w-4 h-4') ?> Download <?= icon('chevron-down', 'w-4 h-4') ?></button>
                        <div x-show="open" x-cloak x-transition class="absolute right-0 z-20 mt-2 w-60 rounded-xl border border-slate-200 bg-white p-1 shadow-xl">
                            <a class="menu-item" href="<?= e(url('jobs/' . $job['id'] . '/download/leads')) ?>"><?= icon('file', 'w-4 h-4') ?> Leads CSV <span class="ml-auto text-xs text-slate-400">outreach-ready</span></a>
                            <a class="menu-item" href="<?= e(url('jobs/' . $job['id'] . '/download/full')) ?>"><?= icon('grid', 'w-4 h-4') ?> Full CSV <span class="ml-auto text-xs text-slate-400">all columns</span></a>
                            <a class="menu-item" href="<?= e(url('jobs/' . $job['id'] . '/download/json')) ?>"><?= icon('database', 'w-4 h-4') ?> JSON</a>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if (can('jobs.cancel')): ?>
                    <form method="post" action="<?= e(url('jobs/' . $job['id'] . '/cancel')) ?>" x-show="active" data-confirm="Stop this job? Places found so far are kept.">
                        <?= csrf_field() ?><button class="btn-secondary text-amber-700"><?= icon('stop', 'w-4 h-4') ?> Cancel</button>
                    </form>
                    <?php if (!$activeJob): ?>
                        <form method="post" action="<?= e(url('jobs/' . $job['id'] . '/retry')) ?>">
                            <?= csrf_field() ?><button class="btn-secondary"><?= icon('refresh', 'w-4 h-4') ?> <?= $job['status'] === 'done' ? 'Run again' : 'Resume' ?></button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if (can('jobs.delete') && !$activeJob): ?>
                    <form method="post" action="<?= e(url('jobs/' . $job['id'])) ?>" data-confirm="Delete this job and all its leads permanently?">
                        <?= csrf_field() ?><?= method_field('DELETE') ?><button class="btn-secondary text-rose-600"><?= icon('trash', 'w-4 h-4') ?></button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="mt-6">
            <div class="flex items-center justify-between text-sm">
                <span class="truncate text-slate-600" x-text="message"><?= e($pr['message'] ?? '') ?></span>
                <span class="font-semibold text-slate-900" x-text="percent + '%'"><?= (int) ($pr['percent'] ?? 0) ?>%</span>
            </div>
            <div class="mt-2 h-2.5 overflow-hidden rounded-full bg-slate-100">
                <div class="h-full rounded-full bg-gradient-to-r from-indigo-500 to-violet-500 transition-all duration-700" :class="active && 'progress-stripes'" :style="'width:' + percent + '%'" style="width: <?= (int) ($pr['percent'] ?? 0) ?>%"></div>
            </div>
        </div>

        <dl class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="stat"><dt>Places</dt><dd x-text="places.toLocaleString()"><?= number_format((int) $job['places_found']) ?></dd></div>
            <div class="stat"><dt>With email</dt><dd x-text="emails.toLocaleString()"><?= number_format((int) $job['emails_found']) ?></dd></div>
            <div class="stat"><dt>API calls</dt><dd><?= number_format((int) $job['api_calls']) ?></dd></div>
            <div class="stat"><dt>Duration</dt><dd><?= $job['started_at'] ? e(gmdate('H:i:s', max(0, strtotime(($job['finished_at'] ?? now_utc()) . ' UTC') - strtotime($job['started_at'] . ' UTC')))) : '—' ?></dd></div>
        </dl>

        <?php if ($job['error']): ?>
            <div class="mt-5 flex gap-3 rounded-xl bg-rose-50 p-4 text-sm text-rose-800 ring-1 ring-rose-200"><?= icon('alert', 'w-4 h-4 mt-0.5 shrink-0') ?><span class="break-words"><?= e($job['error']) ?></span></div>
        <?php endif; ?>
        <?php if ($job['warnings']): ?>
            <div class="mt-3 rounded-xl bg-amber-50 p-4 text-sm text-amber-900 ring-1 ring-amber-200">
                <ul class="list-disc space-y-1 pl-5"><?php foreach (explode("\n", (string) $job['warnings']) as $w): ?><li class="break-words"><?= e($w) ?></li><?php endforeach; ?></ul>
            </div>
        <?php endif; ?>
    </div>

    <div class="card overflow-hidden">
        <div class="flex flex-col gap-2 border-b border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
            <h2 class="section-title">Leads in this job</h2>
            <div class="flex items-center gap-3">
                <form method="get"><select name="sort" class="input input-sm" data-autosubmit>
                    <?php foreach (['newest' => 'Newest', 'rating' => 'Top rated', 'reviews' => 'Most reviews', 'score' => 'Intent score', 'name' => 'Name'] as $k => $l): ?><option value="<?= $k ?>" <?= ($_GET['sort'] ?? '') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
                </select></form>
                <a href="<?= e(url('leads', ['job' => $job['id']])) ?>" class="text-sm font-medium text-indigo-600 hover:underline whitespace-nowrap">Open in Leads</a>
            </div>
        </div>
        <?= \App\Core\View::partial('leads/_table', ['leads' => $leads, 'selectable' => false, 'showJob' => false]) ?>
    </div>
    <?= \App\Core\View::partial('partials/pagination', ['pg' => $pg]) ?>
</div>
