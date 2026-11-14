<div class="card p-8 text-center">
    <div class="mx-auto grid h-12 w-12 place-items-center rounded-2xl bg-amber-100 text-amber-600"><?= icon('settings', 'w-6 h-6') ?></div>
    <h1 class="mt-4 text-lg font-semibold text-slate-900">We'll be right back</h1>
    <p class="mt-2 text-sm text-slate-600"><?= e($message) ?></p>
    <a href="<?= e(url('login')) ?>" class="mt-6 inline-flex text-sm font-medium text-indigo-600 hover:underline">Administrator sign in</a>
</div>
