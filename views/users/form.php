<?php $u = $user; $min = (int) setting('security.password_min_length', 10); ?>
<div class="mx-auto max-w-2xl">
<form method="post" action="<?= e(url($u ? 'users/' . $u['id'] : 'users')) ?>" class="card" autocomplete="off">
    <?= csrf_field() ?>
    <div class="grid gap-4 p-5 sm:grid-cols-2 sm:p-6">
        <div class="sm:col-span-2"><label class="label" for="name">Full name</label><input id="name" name="name" class="input" required maxlength="120" value="<?= e(old('name', $u['name'] ?? '')) ?>"><?= field_error('name') ?></div>
        <div class="sm:col-span-2"><label class="label" for="email">Email</label><input id="email" name="email" type="email" class="input" required maxlength="190" value="<?= e(old('email', $u['email'] ?? '')) ?>"><?= field_error('email') ?></div>
        <div><label class="label" for="role_id">Role</label>
            <select id="role_id" name="role_id" class="input"><?php foreach ($roles as $r): ?><option value="<?= (int) $r['id'] ?>" <?= (int) old('role_id', $u['role_id'] ?? 0) === (int) $r['id'] ? 'selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?></select><?= field_error('role_id') ?></div>
        <div><label class="label" for="status">Status</label>
            <select id="status" name="status" class="input"><?php foreach (['active', 'disabled'] as $s): ?><option value="<?= $s ?>" <?= old('status', $u['status'] ?? 'active') === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select></div>
        <div class="sm:col-span-2"><label class="label" for="password"><?= $u ? 'New password <span class="font-normal text-slate-400">(leave blank to keep)</span>' : 'Password' ?></label>
            <input id="password" name="password" type="password" class="input" autocomplete="new-password" <?= $u ? '' : 'required' ?> minlength="<?= $min ?>">
            <p class="mt-1 text-xs text-slate-500">At least <?= $min ?> characters with upper case, lower case and a number.</p><?= field_error('password') ?></div>
    </div>
    <div class="flex items-center justify-between gap-2 border-t border-slate-100 bg-slate-50/60 px-5 py-4 sm:px-6">
        <a href="<?= e(url('users')) ?>" class="text-sm text-slate-600 hover:text-slate-900">Cancel</a>
        <button class="btn-primary"><?= $u ? 'Save user' : 'Create user' ?></button>
    </div>
</form>
<?php if ($u && (int) $u['id'] !== (int) auth_user()['id']): ?>
    <form method="post" action="<?= e(url('users/' . $u['id'])) ?>" class="mt-4 text-right" data-confirm="Delete this user? Their jobs are kept.">
        <?= csrf_field() ?><?= method_field('DELETE') ?><button class="text-sm font-medium text-rose-600 hover:underline">Delete user</button>
    </form>
<?php endif; ?>
</div>
