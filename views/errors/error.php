<div class="mx-auto max-w-lg py-12 text-center">
    <div class="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-slate-100 text-slate-500"><?= icon($code >= 500 ? 'alert' : ($code === 403 ? 'lock' : 'search'), 'w-7 h-7') ?></div>
    <p class="mt-4 text-sm font-semibold text-indigo-600"><?= e((string) $code) ?></p>
    <h1 class="mt-1 text-2xl font-bold text-slate-900"><?= e(match (true) { $code === 404 => 'Not found', $code === 403 => 'Access denied', $code === 419 => 'Session expired', $code === 429 => 'Slow down', $code >= 500 => 'Something went wrong', default => 'Error' }) ?></h1>
    <p class="mt-2 text-sm text-slate-600 break-words"><?= e($message) ?></p>
    <div class="mt-6 flex justify-center gap-3">
        <a href="<?= e(url("/")) ?>" data-back class="btn-secondary">Go back</a>
        <a href="<?= e(url('/')) ?>" class="btn-primary">Dashboard</a>
    </div>
</div>
