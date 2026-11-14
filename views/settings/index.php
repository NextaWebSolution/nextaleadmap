<?php $settings = \App\Core\App::settings(); ?>
<div class="grid gap-6 lg:grid-cols-[14rem_1fr]">
    <nav class="flex gap-1 overflow-x-auto pb-1 lg:flex-col lg:overflow-visible" aria-label="Settings sections">
        <?php foreach ($tabs as $k => $t): ?>
            <a href="<?= e(url('settings/' . $k)) ?>" class="flex shrink-0 items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium <?= $k === $tab ? 'bg-white text-indigo-700 shadow-sm ring-1 ring-slate-200' : 'text-slate-600 hover:bg-white/70' ?>">
                <?= icon($t['icon'], 'w-4 h-4') ?><?= e($t['label']) ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="space-y-6">
        <form method="post" action="<?= e(url('settings/' . $tab)) ?>" class="card" autocomplete="off">
            <?= csrf_field() ?>
            <div class="border-b border-slate-100 px-5 py-4 sm:px-6"><h2 class="section-title"><?= e($def['label']) ?></h2></div>
            <div class="divide-y divide-slate-100">
            <?php foreach ($def['fields'] as $f):
                [$key, $label, $type] = $f;
                $rule = $f[3] ?? '';
                $options = $f[4] ?? [];
                $help = $f[5] ?? '';
                $name = str_replace('.', '__', $key);
                $val = old($name, null) ?? setting($key);
                $id = 'f_' . $name; ?>
                <div class="grid gap-2 px-5 py-4 sm:grid-cols-3 sm:gap-6 sm:px-6">
                    <div>
                        <label for="<?= e($id) ?>" class="text-sm font-medium text-slate-800"><?= e($label) ?></label>
                        <?php if ($help): ?><p class="mt-0.5 text-xs text-slate-500"><?= e($help) ?></p><?php endif; ?>
                    </div>
                    <div class="sm:col-span-2">
                    <?php if ($type === 'toggle'): ?>
                        <label class="inline-flex cursor-pointer items-center gap-3">
                            <input id="<?= e($id) ?>" type="checkbox" name="<?= e($name) ?>" value="1" class="peer sr-only" <?= filter_var($val, FILTER_VALIDATE_BOOL) ? 'checked' : '' ?>>
                            <span class="toggle"></span><span class="text-sm text-slate-600">Enabled</span>
                        </label>
                    <?php elseif ($type === 'select'): ?>
                        <select id="<?= e($id) ?>" name="<?= e($name) ?>" class="input">
                            <?php foreach ($options as $ov => $ol): ?><option value="<?= e($ov) ?>" <?= (string) $val === (string) $ov ? 'selected' : '' ?>><?= e($ol) ?></option><?php endforeach; ?>
                        </select>
                    <?php elseif ($type === 'secret' || $type === 'secret_text'): $has = $settings->hasSecret($key); ?>
                        <div class="flex items-center gap-2 text-xs"><?= $has ? '<span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 font-medium text-emerald-700 ring-1 ring-emerald-200">' . icon('lock', 'w-3 h-3') . ' Stored encrypted</span>' : '<span class="rounded-full bg-slate-100 px-2 py-0.5 font-medium text-slate-600">Not set</span>' ?></div>
                        <?php if ($type === 'secret'): ?>
                            <input id="<?= e($id) ?>" type="password" name="<?= e($name) ?>" class="input mt-2 font-mono" placeholder="<?= $has ? 'Leave blank to keep the current value' : 'Paste value' ?>" autocomplete="new-password" spellcheck="false">
                        <?php else: ?>
                            <textarea id="<?= e($id) ?>" name="<?= e($name) ?>" rows="3" class="input mt-2 font-mono text-xs" placeholder="<?= $has ? 'Leave blank to keep the current list' : 'One per line' ?>" spellcheck="false"></textarea>
                        <?php endif; ?>
                        <?php if ($has): ?><label class="mt-2 inline-flex items-center gap-2 text-xs text-slate-600"><input type="checkbox" name="<?= e($name) ?>__clear" value="1" class="cb"> Remove stored value</label><?php endif; ?>
                    <?php elseif ($type === 'list'): ?>
                        <textarea id="<?= e($id) ?>" name="<?= e($name) ?>" rows="3" class="input font-mono text-xs"><?= e(is_array($val) ? implode("\n", $val) : (string) $val) ?></textarea>
                    <?php else: ?>
                        <input id="<?= e($id) ?>" type="<?= $type === 'number' ? 'number' : 'text' ?>" <?= $type === 'number' ? 'step="any"' : '' ?> name="<?= e($name) ?>" value="<?= e(is_array($val) ? '' : (string) $val) ?>" class="input">
                    <?php endif; ?>
                    <?= field_error($name) ?>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
            <div class="flex justify-end gap-2 border-t border-slate-100 bg-slate-50/60 px-5 py-4 sm:px-6"><button class="btn-primary">Save changes</button></div>
        </form>

        <?php if ($tab === 'providers'): ?>
            <div class="card p-5 sm:p-6" x-data="providerTest">
                <h3 class="section-title">Test connections</h3>
                <p class="mt-1 text-sm text-slate-500">Runs one small search ("coffee" near New York) with the saved key. Uses one API call.</p>
                <div class="mt-4 flex flex-wrap gap-2">
                    <?php foreach (\App\Services\Providers\ProviderFactory::PROVIDERS as $pid => $meta): ?>
                        <button type="button" class="btn-secondary" @click="run('<?= e(url('settings/providers/test/' . $pid)) ?>', '<?= e($pid) ?>')" :disabled="busy"><?= icon('zap', 'w-4 h-4') ?> Test <?= e($meta[1]) ?></button>
                    <?php endforeach; ?>
                </div>
                <template x-if="result"><div class="mt-4 rounded-xl p-3 text-sm ring-1" :class="result.ok ? 'bg-emerald-50 text-emerald-800 ring-emerald-200' : 'bg-rose-50 text-rose-800 ring-rose-200'" x-text="result.message"></div></template>
            </div>
        <?php endif; ?>

        <?php if ($tab === 'general' && can('settings.manage')): ?>
            <div class="card p-5 sm:p-6">
                <h3 class="section-title">Configuration as JSON</h3>
                <p class="mt-1 text-sm text-slate-500">Export every non-secret setting, or import a file exported from another installation. Secrets (API keys, proxies) are never exported.</p>
                <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center">
                    <a href="<?= e(url('settings/export')) ?>" class="btn-secondary"><?= icon('download', 'w-4 h-4') ?> Export JSON</a>
                    <form method="post" action="<?= e(url('settings/import')) ?>" enctype="multipart/form-data" class="flex flex-1 flex-col gap-2 sm:flex-row" data-confirm="Import and overwrite matching settings?">
                        <?= csrf_field() ?>
                        <input type="file" name="file" accept="application/json,.json" required class="input py-1.5 text-sm file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-1 file:text-sm">
                        <button class="btn-secondary"><?= icon('upload', 'w-4 h-4') ?> Import</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
