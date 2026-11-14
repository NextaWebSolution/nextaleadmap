<?php
/** @var array $pg */
if (($pg['pages'] ?? 1) <= 1) {
    if (($pg['total'] ?? 0) > 0) {
        echo '<p class="mt-4 text-xs text-slate-500">' . number_format((int) $pg['total']) . ' result(s)</p>';
    }
    return;
}
$q = $_GET;
$link = static function (int $p) use ($q): string {
    $q['page'] = $p;
    return '?' . http_build_query($q);
};
$cur = (int) $pg['page'];
$last = (int) $pg['pages'];
$window = array_unique(array_filter([1, $cur - 1, $cur, $cur + 1, $last], static fn ($p) => $p >= 1 && $p <= $last));
sort($window);
?>
<nav class="mt-4 flex flex-col items-center justify-between gap-3 sm:flex-row" aria-label="Pagination">
    <p class="text-xs text-slate-500">Page <?= $cur ?> of <?= $last ?> · <?= number_format((int) $pg['total']) ?> results</p>
    <div class="flex items-center gap-1">
        <a href="<?= e($link(max(1, $cur - 1))) ?>" class="pg-btn <?= $cur <= 1 ? 'pointer-events-none opacity-40' : '' ?>">Prev</a>
        <?php $prev = 0; foreach ($window as $p): ?>
            <?php if ($p - $prev > 1): ?><span class="px-1 text-slate-400">…</span><?php endif; ?>
            <a href="<?= e($link($p)) ?>" class="pg-btn <?= $p === $cur ? 'bg-indigo-600 text-white ring-indigo-600 hover:bg-indigo-600' : '' ?>"><?= $p ?></a>
        <?php $prev = $p; endforeach; ?>
        <a href="<?= e($link(min($last, $cur + 1))) ?>" class="pg-btn <?= $cur >= $last ? 'pointer-events-none opacity-40' : '' ?>">Next</a>
    </div>
</nav>
