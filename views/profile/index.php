<?php $u = auth_user(); $min = (int) setting('security.password_min_length', 10); ?>
<div class="mx-auto grid max-w-4xl gap-6 lg:grid-cols-2">
    <form method="post" action="<?= e(url('profile')) ?>" class="card p-5 sm:p-6">
        <?= csrf_field() ?>
        <h2 class="section-title">Profile</h2>
        <div class="mt-4 space-y-4">
            <div><label class="label" for="name">Name</label><input id="name" name="name" class="input" required maxlength="120" value="<?= e(old('name', $u['name'])) ?>"><?= field_error('name') ?></div>
            <div><label class="label">Email</label><input class="input bg-slate-50" value="<?= e($u['email']) ?>" disabled></div>
            <div><label class="label">Role</label><input class="input bg-slate-50" value="<?= e($u['role_name'] ?? '') ?>" disabled></div>
        </div>
        <button class="btn-primary mt-5">Save</button>
    </form>
    <form method="post" action="<?= e(url('profile/password')) ?>" class="card p-5 sm:p-6" autocomplete="off">
        <?= csrf_field() ?>
        <h2 class="section-title">Change password</h2>
        <div class="mt-4 space-y-4">
            <div><label class="label" for="current_password">Current password</label><input id="current_password" type="password" name="current_password" class="input" autocomplete="current-password" required><?= field_error('current_password') ?></div>
            <div><label class="label" for="password">New password</label><input id="password" type="password" name="password" class="input" autocomplete="new-password" minlength="<?= $min ?>" required><p class="mt-1 text-xs text-slate-500">Min <?= $min ?> characters, upper + lower case and a number.</p><?= field_error('password') ?></div>
            <div><label class="label" for="password_confirmation">Confirm new password</label><input id="password_confirmation" type="password" name="password_confirmation" class="input" autocomplete="new-password" required><?= field_error('password_confirmation') ?></div>
        </div>
        <button class="btn-primary mt-5">Change password</button>
    </form>
</div>
