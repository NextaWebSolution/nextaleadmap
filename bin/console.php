<?php

declare(strict_types=1);

/*
 * NextaLeadMap console.  php bin/console.php <command> [options]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\App;
use App\Core\Migrator;
use App\Repositories\CityRepository;
use App\Repositories\RoleRepository;
use App\Repositories\UserRepository;
use App\Services\Update\DatabaseBackup;
use App\Services\Update\Updater;
use App\Services\Worker;

$cmd = $argv[1] ?? 'help';
$args = array_slice($argv, 2);
$opt = static function (string $name) use ($args): ?string {
    foreach ($args as $a) {
        if ($a === "--$name") {
            return '1';
        }
        if (str_starts_with($a, "--$name=")) {
            return substr($a, strlen($name) + 3);
        }
    }
    return null;
};
$out = static fn (string $m) => fwrite(STDOUT, $m . "\n");
$fail = static function (string $m): never {
    fwrite(STDERR, "✗ $m\n");
    exit(1);
};
$ask = static function (string $q, bool $hidden = false): string {
    fwrite(STDOUT, $q);
    if ($hidden && DIRECTORY_SEPARATOR === '/') {
        system('stty -echo');
        $v = trim((string) fgets(STDIN));
        system('stty echo');
        fwrite(STDOUT, "\n");
        return $v;
    }
    return trim((string) fgets(STDIN));
};

$help = <<<TXT
NextaLeadMap console (v%s)

  migrate                     Run pending database migrations
  migrate:status              List applied / pending migrations
  migrate:rollback            Roll back the latest migration batch
  user:create                 Create a user (--email= --name= --role=admin)
  user:password               Reset a user's password (--email=)
  worker:run                  Process the queue once (--seconds=55)
  update:check                Check the update server
  update:apply                Download, verify and install the latest signed release
  update:recover              Restore files after an interrupted update
  backup:db                   Write a gzip SQL dump to storage/backups
  db:restore <file> --force   Restore a dump made by backup:db / the updater
  cities:import               Re-import the bundled city presets
  maintenance:on|off          Toggle maintenance mode
  assets:vendor               Self-host Alpine.js + Chart.js (removes the CDN from the CSP)
  about                       Show environment info

TXT;

if (!in_array($cmd, ['help', 'about', 'update:recover', 'maintenance:on', 'maintenance:off'], true) && !App::installed()) {
    $fail('Not installed. Run the web installer first.');
}

switch ($cmd) {
    case 'migrate':
        $r = (new Migrator(App::db(), App::$root . '/database/migrations'))->migrate();
        $out($r['ran'] ? '✓ Ran: ' . implode(', ', $r['ran']) : '✓ Nothing to migrate');
        break;

    case 'migrate:status':
        $m = new Migrator(App::db(), App::$root . '/database/migrations');
        $pending = $m->pending();
        foreach ($m->files() as $f) {
            $out((in_array($f, $pending, true) ? '  pending  ' : '  applied  ') . $f);
        }
        break;

    case 'migrate:rollback':
        $done = (new Migrator(App::db(), App::$root . '/database/migrations'))->rollback();
        $out($done ? '✓ Rolled back: ' . implode(', ', $done) : 'Nothing to roll back');
        break;

    case 'user:create':
        $email = $opt('email') ?? $ask('Email: ');
        $name = $opt('name') ?? $ask('Name: ');
        $role = (new RoleRepository(App::db()))->findBySlug($opt('role') ?? 'admin') ?? $fail('Unknown role');
        $pass = $ask('Password (min 10, upper+lower+digit): ', true);
        if (strlen($pass) < 10 || !App\Core\Validator::strongPassword($pass) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $fail('Invalid email or weak password');
        }
        $id = (new UserRepository(App::db()))->create(['name' => $name, 'email' => $email, 'password' => $pass, 'role_id' => (int) $role['id']]);
        $out("✓ User #$id created");
        break;

    case 'user:password':
        $email = $opt('email') ?? $ask('Email: ');
        $repo = new UserRepository(App::db());
        $u = $repo->findByEmail($email) ?? $fail('No such user');
        $pass = $ask('New password: ', true);
        if (strlen($pass) < 10 || !App\Core\Validator::strongPassword($pass)) {
            $fail('Weak password');
        }
        $repo->update((int) $u['id'], ['password' => $pass, 'status' => 'active']);
        App::db()->update('users', ['failed_logins' => 0, 'locked_until' => null], 'id = ?', [(int) $u['id']]);
        $out('✓ Password reset; account unlocked');
        break;

    case 'worker:run':
        $n = (new Worker(static fn ($m) => fwrite(STDOUT, $m . "\n")))->runFor((int) ($opt('seconds') ?? 55));
        $out("✓ $n job(s) processed");
        break;

    case 'update:check':
        $r = (new Updater(App::$root))->check();
        $out($r['message']);
        if ($r['manifest']) {
            $out('Notes: ' . ($r['manifest']['release_notes'] ?? ''));
        }
        break;

    case 'update:apply':
        $u = new Updater(App::$root);
        try {
            $u->apply(null);
            $out($u->logText());
            $out('✓ Updated to ' . trim((string) file_get_contents(App::$root . '/VERSION')));
        } catch (Throwable $e) {
            $out($u->logText());
            $fail($e->getMessage());
        }
        break;

    case 'update:recover':
        $out((new Updater(App::$root))->recover());
        break;

    case 'backup:db':
        $f = App::$root . '/storage/backups/db-manual-' . gmdate('Ymd-His') . '.sql.gz';
        $n = (new DatabaseBackup(App::db()))->dump($f);
        $out("✓ $n rows → $f");
        break;

    case 'db:restore':
        $file = $args[0] ?? '';
        $real = realpath($file) ?: realpath(App::$root . '/storage/backups/' . basename($file));
        if (!$real || !str_ends_with($real, '.sql.gz')) {
            $fail('Give a .sql.gz file from storage/backups');
        }
        if ($opt('force') !== '1') {
            $fail('This REPLACES all application tables. Re-run with --force to continue.');
        }
        (new DatabaseBackup(App::db()))->restore($real);
        $out('✓ Database restored from ' . basename($real));
        break;

    case 'cities:import':
        $n = (new CityRepository(App::db()))->import(App::$root . '/database/presets/cities.tsv.gz');
        $out("✓ $n cities imported");
        break;

    case 'maintenance:on':
        (new Updater(App::$root))->maintenance(true, $opt('message') ?? 'Scheduled maintenance.');
        $out('✓ Maintenance mode ON');
        break;

    case 'maintenance:off':
        (new Updater(App::$root))->maintenance(false);
        $out('✓ Maintenance mode OFF');
        break;

    case 'assets:vendor':
        $dir = App::$root . '/public/assets/vendor';
        $http = new App\Core\HttpClient([], 'NextaLeadMap/' . App::version());
        $sri = [];
        foreach (App\Core\Response::VENDOR as $name => [$file, $cdn]) {
            $tmp = $dir . '/' . $file . '.part';
            $http->downloadTo($cdn, $tmp, 2 * 1024 * 1024, 60);
            $body = (string) file_get_contents($tmp);
            if (strlen($body) < 10000 || stripos($body, '<html') !== false) {
                @unlink($tmp);
                $fail("$name: unexpected download content");
            }
            rename($tmp, $dir . '/' . $file);
            $sri[$file] = 'sha384-' . base64_encode(hash('sha384', $body, true));
            $out("✓ $name → public/assets/vendor/$file ({$sri[$file]})");
        }
        file_put_contents($dir . '/sri.json', json_encode($sri, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        $out('Compare these SHA-384 values with https://www.jsdelivr.com before trusting them. CDN is now removed from the CSP.');
        break;

    case 'about':
        $out('NextaLeadMap ' . App::version());
        $out('PHP ' . PHP_VERSION . ' · installed: ' . (App::installed() ? 'yes' : 'no') . ' · maintenance: ' . (App::maintenance() ? 'on' : 'off'));
        $out('Update key: ' . ((new Updater(App::$root))->verifier()->configured() ? 'configured' : 'not configured'));
        break;

    default:
        $out(sprintf($help, App::version()));
}
