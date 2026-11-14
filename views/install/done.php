<div class="text-center">
    <div class="mx-auto grid h-12 w-12 place-items-center rounded-2xl bg-emerald-100 text-emerald-600"><?= icon('check-circle', 'w-6 h-6') ?></div>
    <h1 class="mt-3 text-lg font-semibold text-slate-900">NextaLeadMap is installed</h1>
</div>
<ul class="mt-5 space-y-1 rounded-xl bg-slate-50 p-4 text-sm text-slate-700"><?php foreach ($log as $l): ?><li class="flex gap-2"><?= icon('check', 'w-4 h-4 text-emerald-600 mt-0.5') ?><?= e($l) ?></li><?php endforeach; ?></ul>
<div class="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
    <b>Next steps</b>
    <ol class="mt-2 list-decimal space-y-1 pl-5">
        <li>Add the worker cron job (System page shows the exact command).</li>
        <li>Add a Google Places or SerpApi key in Settings → Data providers.</li>
        <li>Back up <code>.env</code> somewhere safe — its APP_KEY decrypts your stored API keys.</li>
    </ol>
</div>
<div class="mt-6 text-center"><a href="<?= e($appUrl . '/login') ?>" class="btn-primary">Sign in</a></div>
