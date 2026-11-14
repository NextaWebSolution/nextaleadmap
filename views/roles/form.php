<?php $r = $role; $isAdmin = ($r['slug'] ?? '') === 'admin'; $have = $r['permissions'] ?? []; ?>
<div class="mx-auto max-w-3xl">
<form method="post" action="<?= e(url($r ? 'roles/' . $r['id'] : 'roles')) ?>" class="card">
    <?= csrf_field() ?>
    <div class="grid gap-4 p-5 sm:grid-cols-2 sm:p-6">
        <div><label class="label" for="name">Name</label><input id="name" name="name" class="input" required value="<?= e(old('name', $r['name'] ?? '')) ?>"><?= field_error('name') ?></div>
        <div><label class="label" for="slug">Slug</label><input id="slug" name="slug" class="input font-mono" <?= $r ? 'disabled' : 'required' ?> pattern="[a-z][a-z0-9_-]{1,49}" value="<?= e(old('slug', $r['slug'] ?? '')) ?>"><?= field_error('slug') ?></div>
        <div class="sm:col-span-2"><label class="label" for="description">Description</label><input id="description" name="description" class="input" maxlength="255" value="<?= e(old('description', $r['description'] ?? '')) ?>"></div>
    </div>
    <div class="border-t border-slate-100 p-5 sm:p-6">
        <h3 class="section-title">Permissions</h3>
        <?php if ($isAdmin): ?><p class="mt-2 text-sm text-slate-500">The Administrator role always has every permission.</p><?php else: ?>
        <div class="mt-3 grid gap-2 sm:grid-cols-2">
            <?php foreach ($defs as $p => $label): ?>
                <label class="flex items-start gap-3 rounded-xl border border-slate-200 p-3 hover:bg-slate-50">
                    <input type="checkbox" name="permissions[]" value="<?= e($p) ?>" class="cb mt-0.5" <?= in_array($p, (array) old('permissions', $have), true) ? 'checked' : '' ?>>
                    <span><span class="block text-sm font-medium text-slate-800"><?= e($label) ?></span><span class="block font-mono text-[11px] text-slate-400"><?= e($p) ?></span></span>
                </label>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <div class="flex items-center justify-between border-t border-slate-100 bg-slate-50/60 px-5 py-4 sm:px-6">
        <a href="<?= e(url('roles')) ?>" class="text-sm text-slate-600">Cancel</a><button class="btn-primary">Save role</button>
    </div>
</form>
<?php if ($r && !$r['is_system']): ?>
    <form method="post" action="<?= e(url('roles/' . $r['id'])) ?>" class="mt-4 text-right" data-confirm="Delete this role?"><?= csrf_field() ?><?= method_field('DELETE') ?><button class="text-sm font-medium text-rose-600 hover:underline">Delete role</button></form>
<?php endif; ?>
</div>
