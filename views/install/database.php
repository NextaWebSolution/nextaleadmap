<h1 class="text-lg font-semibold text-slate-900">Database</h1>
<p class="mt-1 text-sm text-slate-500">Create an empty database and user in cPanel → MySQL® Databases, then enter them here.</p>
<form method="post" action="<?= e(url('install/database')) ?>" class="mt-5 grid gap-4 sm:grid-cols-2" autocomplete="off">
    <?= csrf_field() ?>
    <div><label class="label" for="db_host">Host</label><input id="db_host" name="db_host" class="input" value="<?= e(old('db_host', 'localhost')) ?>" required></div>
    <div><label class="label" for="db_port">Port</label><input id="db_port" name="db_port" type="number" class="input" value="<?= e(old('db_port', '3306')) ?>" required></div>
    <div><label class="label" for="db_name">Database name</label><input id="db_name" name="db_name" class="input" value="<?= e(old('db_name')) ?>" required></div>
    <div><label class="label" for="db_prefix">Table prefix</label><input id="db_prefix" name="db_prefix" class="input" value="<?= e(old('db_prefix', 'nlm_')) ?>"></div>
    <div><label class="label" for="db_user">Username</label><input id="db_user" name="db_user" class="input" value="<?= e(old('db_user')) ?>" required autocomplete="off"></div>
    <div><label class="label" for="db_password">Password</label><input id="db_password" name="db_password" type="password" class="input" autocomplete="new-password"></div>
    <div class="sm:col-span-2"><?= field_error('db_host') ?></div>
    <div class="sm:col-span-2 flex justify-between"><a href="<?= e(url('install')) ?>" class="btn-secondary">Back</a><button class="btn-primary">Test &amp; continue</button></div>
</form>
