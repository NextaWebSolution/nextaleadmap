<?php if ($newKey): ?>
    <div class="mb-6 rounded-2xl border border-emerald-200 bg-emerald-50 p-5" x-data="{ copied: false }">
        <div class="flex items-center gap-2 font-semibold text-emerald-900"><?= icon('check-circle', 'w-5 h-5') ?> Your new API key</div>
        <p class="mt-1 text-sm text-emerald-800">Copy it now — it is shown only once and stored only as a hash.</p>
        <div class="mt-3 flex flex-col gap-2 sm:flex-row">
            <input readonly value="<?= e($newKey) ?>" class="input flex-1 font-mono text-xs" id="new-key" @focus="$el.select()">
            <button type="button" class="btn-primary" data-copy="#new-key" @click="copied = true"><?= icon('copy', 'w-4 h-4') ?> <span x-text="copied ? 'Copied' : 'Copy'">Copy</span></button>
        </div>
    </div>
<?php endif; ?>
<div class="grid gap-6 lg:grid-cols-3">
    <form method="post" action="<?= e(url('api-keys')) ?>" class="card p-5 sm:p-6 lg:order-2">
        <?= csrf_field() ?>
        <h2 class="section-title">Create a key</h2>
        <div class="mt-4 space-y-4">
            <div><label class="label" for="name">Name</label><input id="name" name="name" required maxlength="100" class="input" placeholder="e.g. CRM sync" value="<?= e(old('name')) ?>"><?= field_error('name') ?></div>
            <fieldset><legend class="label">Scopes</legend>
                <div class="space-y-2"><?php foreach ($scopes as $s => $d): ?>
                    <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="scopes[]" value="<?= e($s) ?>" class="cb mt-0.5" <?= str_ends_with($s, ':read') ? 'checked' : '' ?>><span><code class="font-mono text-xs text-slate-800"><?= e($s) ?></code><span class="block text-xs text-slate-500"><?= e($d) ?></span></span></label>
                <?php endforeach; ?></div><?= field_error('scopes') ?>
            </fieldset>
            <div><label class="label" for="expires_days">Expires</label>
                <select id="expires_days" name="expires_days" class="input"><option value="0">Never</option><option value="30">30 days</option><option value="90" selected>90 days</option><option value="365">1 year</option></select></div>
            <button class="btn-primary w-full justify-center"><?= icon('key', 'w-4 h-4') ?> Generate key</button>
        </div>
    </form>
    <div class="card overflow-hidden lg:col-span-2 lg:order-1">
        <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
            <h2 class="section-title"><?= $all ? 'All API keys' : 'Your API keys' ?></h2>
            <?php if (can('users.manage')): ?><a class="text-sm text-indigo-600 hover:underline" href="<?= e(url('api-keys', $all ? [] : ['all' => 1])) ?>"><?= $all ? 'Show mine' : 'Show all users' ?></a><?php endif; ?>
        </div>
        <?php if (!$keys): ?><p class="px-5 py-10 text-center text-sm text-slate-500">No API keys yet.</p><?php endif; ?>
        <ul class="divide-y divide-slate-100">
        <?php foreach ($keys as $k): $revoked = $k['revoked_at'] || ($k['expires_at'] && strtotime($k['expires_at'] . ' UTC') < time()); ?>
            <li class="flex flex-col gap-2 p-4 sm:flex-row sm:items-center sm:px-5 <?= $revoked ? 'opacity-60' : '' ?>">
                <div class="min-w-0 flex-1">
                    <div class="font-medium text-slate-900"><?= e($k['name']) ?> <?= $all ? '<span class="text-xs font-normal text-slate-500">· ' . e($k['user_name']) . '</span>' : '' ?></div>
                    <div class="font-mono text-xs text-slate-500">nlm_<?= e($k['prefix']) ?>_••••••••</div>
                    <div class="mt-1 flex flex-wrap gap-1"><?php foreach ($k['scopes'] as $s): ?><span class="chip pointer-events-none py-0 text-[11px]"><?= e($s) ?></span><?php endforeach; ?></div>
                </div>
                <div class="text-xs text-slate-500 sm:text-right">
                    <div>Last used <?= e(time_ago($k['last_used_at'])) ?></div>
                    <div><?= $k['revoked_at'] ? 'Revoked' : ($k['expires_at'] ? 'Expires ' . e(fmt_date($k['expires_at'], 'd M Y')) : 'No expiry') ?></div>
                </div>
                <?php if (!$k['revoked_at']): ?>
                    <form method="post" action="<?= e(url('api-keys/' . $k['id'])) ?>" data-confirm="Revoke this key? Apps using it stop working immediately.">
                        <?= csrf_field() ?><?= method_field('DELETE') ?><button class="btn-secondary py-1.5 text-rose-600">Revoke</button>
                    </form>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
        </ul>
    </div>
</div>
<div class="card mt-6 p-5 sm:p-6">
    <h2 class="section-title">Quick start</h2>
    <pre class="mt-3 overflow-x-auto rounded-xl bg-slate-900 p-4 text-xs leading-relaxed text-slate-100"><code>curl -X POST <?= e(rtrim((string) \App\Core\Env::get('APP_URL', ''), '/')) ?>/api/v1/jobs \
  -H "Authorization: Bearer nlm_xxx" -H "Content-Type: application/json" \
  -d '{"keywords":["dentists"],"location_mode":"city","city":"Pune, Maharashtra","grid":2,"emails":true}'

curl -H "Authorization: Bearer nlm_xxx" <?= e(rtrim((string) \App\Core\Env::get('APP_URL', ''), '/')) ?>/api/v1/jobs/JOB_ID
curl -H "Authorization: Bearer nlm_xxx" -o leads.csv "<?= e(rtrim((string) \App\Core\Env::get('APP_URL', ''), '/')) ?>/api/v1/jobs/JOB_ID/download?format=leads"</code></pre>
    <p class="mt-2 text-xs text-slate-500">Full endpoint list: <a class="link" href="<?= e(url('api/v1')) ?>" target="_blank">/api/v1</a></p>
</div>
