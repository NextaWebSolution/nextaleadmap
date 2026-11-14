<?php
$styles = [
    'success' => ['bg-emerald-50 text-emerald-800 ring-emerald-200', 'check-circle'],
    'error' => ['bg-rose-50 text-rose-800 ring-rose-200', 'alert'],
    'warning' => ['bg-amber-50 text-amber-800 ring-amber-200', 'alert'],
    'info' => ['bg-sky-50 text-sky-800 ring-sky-200', 'info'],
];
foreach (flashes() as $f):
    [$cls, $ic] = $styles[$f['type']] ?? $styles['info']; ?>
    <div x-data="{ show: true }" x-show="show" x-transition class="mb-4 flex items-start gap-3 rounded-xl px-4 py-3 text-sm ring-1 ring-inset <?= $cls ?>" role="status">
        <span class="mt-0.5 shrink-0"><?= icon($ic, 'w-4 h-4') ?></span>
        <div class="flex-1 break-words"><?= e($f['message']) ?></div>
        <button type="button" class="shrink-0 opacity-60 hover:opacity-100" @click="show = false" aria-label="Dismiss"><?= icon('x', 'w-4 h-4') ?></button>
    </div>
<?php endforeach; ?>
