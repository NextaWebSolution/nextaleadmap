<?php

declare(strict_types=1);

use App\Controllers\Api\JobApiController;
use App\Controllers\Api\LeadApiController;
use App\Controllers\ApiKeyController;
use App\Controllers\AuditController;
use App\Controllers\AuthController;
use App\Controllers\CronController;
use App\Controllers\DashboardController;
use App\Controllers\InstallController;
use App\Controllers\JobController;
use App\Controllers\LeadController;
use App\Controllers\PresetController;
use App\Controllers\ProfileController;
use App\Controllers\RoleController;
use App\Controllers\SettingsController;
use App\Controllers\SystemController;
use App\Controllers\UserController;
use App\Core\Router;
use App\Middleware\ApiAuth;
use App\Middleware\Authenticate;
use App\Middleware\Can;
use App\Middleware\InstallerGate;
use App\Middleware\Scope;
use App\Middleware\WebMiddleware;

return static function (Router $r): void {
    $id = '{id:[0-9a-f-]{36}}';
    $num = '{id:\d+}';

    // ── Installer (only before installation) ──
    $r->group(['prefix' => '/install', 'middleware' => [InstallerGate::class]], function (Router $r) {
        $r->get('', [InstallController::class, 'requirements']);
        $r->get('/database', [InstallController::class, 'database']);
        $r->post('/database', [InstallController::class, 'saveDatabase']);
        $r->get('/app', [InstallController::class, 'app']);
        $r->post('/run', [InstallController::class, 'run']);
    });

    // ── Machine endpoints ──
    $r->get('/health', [CronController::class, 'health']);
    $r->get('/cron/run', [CronController::class, 'run']);

    // ── REST API v1 ──
    $r->get('/api/v1', [LeadApiController::class, 'docs']);
    $r->group(['prefix' => '/api/v1', 'middleware' => [ApiAuth::class]], function (Router $r) use ($id, $num) {
        $r->get('/me', [LeadApiController::class, 'me']);
        $r->get('/jobs', [JobApiController::class, 'index'], [new Scope('jobs:read', 'jobs.view')]);
        $r->post('/jobs', [JobApiController::class, 'store'], [new Scope('jobs:write', 'jobs.create')]);
        $r->get("/jobs/$id", [JobApiController::class, 'show'], [new Scope('jobs:read', 'jobs.view')]);
        $r->post("/jobs/$id/cancel", [JobApiController::class, 'cancel'], [new Scope('jobs:write', 'jobs.cancel')]);
        $r->delete("/jobs/$id", [JobApiController::class, 'destroy'], [new Scope('jobs:write', 'jobs.delete')]);
        $r->get("/jobs/$id/download", [JobApiController::class, 'download'], [new Scope('jobs:read', 'leads.export')]);
        $r->get('/leads', [LeadApiController::class, 'index'], [new Scope('leads:read', 'leads.view')]);
        $r->get("/leads/$num", [LeadApiController::class, 'show'], [new Scope('leads:read', 'leads.view')]);
        $r->put("/leads/$num", [LeadApiController::class, 'update'], [new Scope('leads:write', 'leads.update')]);
        $r->get('/presets/cities', [PresetController::class, 'cities'], [new Scope('jobs:read', 'jobs.view')]);
        $r->get('/presets/business-types', [PresetController::class, 'businessTypes'], [new Scope('jobs:read', 'jobs.view')]);
    });

    // ── Web app ──
    $r->group(['middleware' => [WebMiddleware::class]], function (Router $r) use ($id, $num) {
        $r->get('/login', [AuthController::class, 'showLogin']);
        $r->post('/login', [AuthController::class, 'login']);
        $r->post('/logout', [AuthController::class, 'logout']);

        $r->group(['middleware' => [Authenticate::class]], function (Router $r) use ($id, $num) {
            $r->get('/', [DashboardController::class, 'index'], [new Can('dashboard.view')]);

            $r->get('/jobs', [JobController::class, 'index'], [new Can('jobs.view')]);
            $r->get('/jobs/create', [JobController::class, 'create'], [new Can('jobs.create')]);
            $r->post('/jobs', [JobController::class, 'store'], [new Can('jobs.create')]);
            $r->get("/jobs/$id", [JobController::class, 'show'], [new Can('jobs.view')]);
            $r->get("/jobs/$id/status", [JobController::class, 'status'], [new Can('jobs.view')]);
            $r->post("/jobs/$id/cancel", [JobController::class, 'cancel'], [new Can('jobs.cancel')]);
            $r->post("/jobs/$id/retry", [JobController::class, 'retry'], [new Can('jobs.cancel')]);
            $r->delete("/jobs/$id", [JobController::class, 'destroy'], [new Can('jobs.delete')]);
            $r->get("/jobs/$id/download/{format:leads|full|json}", [JobController::class, 'download'], [new Can('leads.export')]);

            $r->get('/leads', [LeadController::class, 'index'], [new Can('leads.view')]);
            $r->get('/leads/export', [LeadController::class, 'export'], [new Can('leads.export')]);
            $r->post('/leads/bulk', [LeadController::class, 'bulk'], [new Can('leads.update')]);
            $r->get("/leads/$num", [LeadController::class, 'show'], [new Can('leads.view')]);
            $r->post("/leads/$num", [LeadController::class, 'update'], [new Can('leads.update')]);

            $r->get('/presets/cities', [PresetController::class, 'cities'], [new Can('jobs.create')]);
            $r->get('/presets/business-types', [PresetController::class, 'businessTypes'], [new Can('jobs.create')]);

            $r->get('/api-keys', [ApiKeyController::class, 'index'], [new Can('apikeys.manage')]);
            $r->post('/api-keys', [ApiKeyController::class, 'store'], [new Can('apikeys.manage')]);
            $r->delete("/api-keys/$num", [ApiKeyController::class, 'destroy'], [new Can('apikeys.manage')]);

            $r->get('/profile', [ProfileController::class, 'show']);
            $r->post('/profile', [ProfileController::class, 'update']);
            $r->post('/profile/password', [ProfileController::class, 'password']);

            $r->group(['middleware' => [new Can('settings.manage', 'providers.manage', 'system.manage')]], function (Router $r) {
                $r->get('/settings', [SettingsController::class, 'index']);
                $r->get('/settings/export', [SettingsController::class, 'export'], [new Can('settings.manage')]);
                $r->post('/settings/import', [SettingsController::class, 'import'], [new Can('settings.manage')]);
                $r->post('/settings/providers/test/{pid:[a-z_]+}', [SettingsController::class, 'testProvider'], [new Can('providers.manage')]);
                $r->get('/settings/{tab:[a-z]+}', [SettingsController::class, 'show']);
                $r->post('/settings/{tab:[a-z]+}', [SettingsController::class, 'save']);
            });

            $r->group(['middleware' => [new Can('users.manage')]], function (Router $r) use ($num) {
                $r->get('/users', [UserController::class, 'index']);
                $r->get('/users/create', [UserController::class, 'create']);
                $r->post('/users', [UserController::class, 'store']);
                $r->get("/users/$num/edit", [UserController::class, 'edit']);
                $r->post("/users/$num", [UserController::class, 'update']);
                $r->delete("/users/$num", [UserController::class, 'destroy']);
            });
            $r->group(['middleware' => [new Can('roles.manage')]], function (Router $r) use ($num) {
                $r->get('/roles', [RoleController::class, 'index']);
                $r->get('/roles/create', [RoleController::class, 'create']);
                $r->post('/roles', [RoleController::class, 'store']);
                $r->get("/roles/$num/edit", [RoleController::class, 'edit']);
                $r->post("/roles/$num", [RoleController::class, 'update']);
                $r->delete("/roles/$num", [RoleController::class, 'destroy']);
            });

            $r->get('/audit', [AuditController::class, 'index'], [new Can('audit.view')]);

            $r->group(['prefix' => '/system', 'middleware' => [new Can('system.manage')]], function (Router $r) {
                $r->get('', [SystemController::class, 'index']);
                $r->post('/update/check', [SystemController::class, 'checkUpdate']);
                $r->post('/update/apply', [SystemController::class, 'applyUpdate']);
                $r->post('/backup/db', [SystemController::class, 'backupDb']);
                $r->post('/backup/restore', [SystemController::class, 'restoreBackup']);
                $r->get('/backups/{name:[A-Za-z0-9._-]+}', [SystemController::class, 'downloadBackup']);
                $r->delete('/backups/{name:[A-Za-z0-9._-]+}', [SystemController::class, 'deleteBackup']);
                $r->post('/maintenance', [SystemController::class, 'maintenance']);
                $r->post('/worker', [SystemController::class, 'runWorker']);
                $r->post('/cron-token', [SystemController::class, 'rotateCronToken']);
                $r->post('/migrate', [SystemController::class, 'migrate']);
            });
        });
    });
};
