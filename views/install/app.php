<h1 class="text-lg font-semibold text-slate-900">Application &amp; administrator</h1>
<p class="mt-1 text-sm text-slate-500">This creates your settings, the encryption key and the first admin account.</p>
<form method="post" action="<?= e(url('install/run')) ?>" class="mt-5 grid gap-4 sm:grid-cols-2" autocomplete="off">
    <?= csrf_field() ?>
    <div><label class="label" for="app_name">App name</label><input id="app_name" name="app_name" class="input" value="<?= e(old('app_name', 'NextaLeadMap')) ?>" required><?= field_error('app_name') ?></div>
    <div><label class="label" for="timezone">Timezone</label><select id="timezone" name="timezone" class="input"><?php $tz = old('timezone', 'Asia/Kolkata'); foreach ($timezones as $t): ?><option <?= $t === $tz ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
    <div class="sm:col-span-2"><label class="label" for="app_url">App URL</label><input id="app_url" name="app_url" type="url" class="input" value="<?= e(old('app_url', $urlGuess)) ?>" required><p class="mt-1 text-xs text-slate-500">The address you open the app at, without a trailing slash. Use https:// in production.</p><?= field_error('app_url') ?></div>
    <div class="sm:col-span-2 mt-2 border-t border-slate-100 pt-4 text-sm font-semibold text-slate-800">Administrator</div>
    <div><label class="label" for="admin_name">Name</label><input id="admin_name" name="admin_name" class="input" value="<?= e(old('admin_name')) ?>" required><?= field_error('admin_name') ?></div>
    <div><label class="label" for="admin_email">Email</label><input id="admin_email" name="admin_email" type="email" class="input" value="<?= e(old('admin_email')) ?>" required><?= field_error('admin_email') ?></div>
    <div><label class="label" for="admin_password">Password</label><input id="admin_password" name="admin_password" type="password" minlength="10" class="input" autocomplete="new-password" required><?= field_error('admin_password') ?></div>
    <div><label class="label" for="admin_password_confirmation">Confirm password</label><input id="admin_password_confirmation" name="admin_password_confirmation" type="password" class="input" autocomplete="new-password" required><?= field_error('admin_password_confirmation') ?></div>
    <p class="sm:col-span-2 text-xs text-slate-500">Min 10 characters with upper case, lower case and a number. Importing 168k city presets takes ~20 seconds.</p>
    <div class="sm:col-span-2 flex justify-between"><a href="<?= e(url('install/database')) ?>" class="btn-secondary">Back</a><button class="btn-primary"><?= icon('zap', 'w-4 h-4') ?> Install</button></div>
</form>
