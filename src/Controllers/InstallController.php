<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Env;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Core\View;
use App\Services\Installer;
use Throwable;

final class InstallController extends Controller
{
    private function page2(string $view, array $data = []): Response
    {
        return Response::html(View::render('install/' . $view, $data + ['title' => 'Install NextaLeadMap'], 'layouts/install'));
    }

    public function requirements(Request $r): Response
    {
        return $this->page2('requirements', ['checks' => Installer::requirements(), 'ok' => Installer::requirementsMet(), 'step' => 1]);
    }

    public function database(Request $r): Response
    {
        if (!Installer::requirementsMet()) {
            return Response::redirect(url('install'));
        }
        if (is_file(App::$root . '/.env') && Env::get('DB_DATABASE')) {
            $_SESSION['install_db'] = 'env';
            return Response::redirect(url('install/app'));
        }
        return $this->page2('database', ['step' => 2]);
    }

    public function saveDatabase(Request $r): Response
    {
        $in = [
            'host' => $r->str('db_host', '127.0.0.1', 255), 'port' => $r->int('db_port', 3306), 'database' => $r->str('db_name', '', 64),
            'username' => $r->str('db_user', '', 64), 'password' => (string) $r->input('db_password', ''), 'prefix' => $r->str('db_prefix', 'nlm_', 20),
        ];
        try {
            Installer::testDb($in);
        } catch (Throwable $e) {
            Session::flashInput((array) $r->input(), ['db_host' => $e->getMessage()]);
            return Response::redirect(url('install/database'));
        }
        $_SESSION['install_db'] = $in;
        return Response::redirect(url('install/app'));
    }

    public function app(Request $r): Response
    {
        if (empty($_SESSION['install_db'])) {
            return Response::redirect(url('install/database'));
        }
        $scheme = $r->isSecure() ? 'https' : 'http';
        $host = preg_replace('/[^A-Za-z0-9.:-]/', '', (string) ($r->server['HTTP_HOST'] ?? 'localhost'));
        $guess = $scheme . '://' . $host . App::basePath();
        return $this->page2('app', ['step' => 3, 'urlGuess' => $guess, 'timezones' => timezone_identifiers_list()]);
    }

    public function run(Request $r): Response
    {
        $db = $_SESSION['install_db'] ?? null;
        if (!$db) {
            return Response::redirect(url('install/database'));
        }
        $data = (array) $r->input();
        $v = Validator::make($data, [
            'app_name' => 'required|max:60',
            'app_url' => 'required|url|max:255',
            'timezone' => 'required|max:64',
            'admin_name' => 'required|max:120',
            'admin_email' => 'required|email|max:190',
            'admin_password' => 'required|min:10|max:200|password',
            'admin_password_confirmation' => 'required|same:admin_password',
        ]);
        if (!$v->fails() && !in_array($data['timezone'], timezone_identifiers_list(), true)) {
            $v->errors['timezone'] = 'Choose a valid timezone.';
        }
        if ($v->errors) {
            Session::flashInput($data, $v->errors);
            return Response::redirect(url('install/app'));
        }
        try {
            $log = Installer::run(
                $db === 'env' ? [] : $db,
                ['name' => trim($data['app_name']), 'url' => trim($data['app_url']), 'timezone' => $data['timezone']],
                ['name' => trim($data['admin_name']), 'email' => trim($data['admin_email']), 'password' => (string) $data['admin_password']],
            );
        } catch (Throwable $e) {
            App::logger()->error('install failed', ['e' => $e->getMessage()]);
            Session::flashInput($data, ['app_name' => 'Installation failed: ' . $e->getMessage()]);
            return Response::redirect(url('install/app'));
        }
        unset($_SESSION['install_db']);
        Session::regenerate();
        return Response::html(View::render('install/done', ['log' => $log, 'step' => 4, 'title' => 'Installed', 'appUrl' => rtrim($data['app_url'], '/')], 'layouts/install'));
    }
}
