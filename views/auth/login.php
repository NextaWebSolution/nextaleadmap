<div class="card p-6 sm:p-8">
    <h1 class="text-lg font-semibold text-slate-900">Sign in</h1>
    <p class="mt-1 text-sm text-slate-500">Use the account your administrator created for you.</p>
    <form method="post" action="<?= e(url('login')) ?>" class="mt-6 space-y-4" novalidate>
        <?= csrf_field() ?>
        <div>
            <label for="email" class="label">Email</label>
            <input id="email" name="email" type="email" autocomplete="username" required autofocus class="input" value="<?= e(old('email')) ?>">
            <?= field_error('email') ?>
        </div>
        <div x-data="{ show: false }">
            <label for="password" class="label">Password</label>
            <div class="relative">
                <input id="password" name="password" :type="show ? 'text' : 'password'" type="password" autocomplete="current-password" required class="input pr-10">
                <button type="button" class="absolute inset-y-0 right-0 px-3 text-slate-400 hover:text-slate-600" @click="show = !show" aria-label="Show password"><?= icon('eye', 'w-4 h-4') ?></button>
            </div>
        </div>
        <button class="btn-primary w-full justify-center py-2.5"><?= icon('lock', 'w-4 h-4') ?> Sign in</button>
    </form>
</div>
<p class="mt-6 text-center text-xs text-slate-400">Forgot your password? Ask an administrator, or run <code class="font-mono">php bin/console.php user:password</code>.</p>
<?= vendor_script('alpine') ?>
