<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\ApiKeyRepository;
use App\Services\Audit;

final class ApiKeyController extends Controller
{
    public function index(Request $r): Response
    {
        $all = App::auth()->can('users.manage') && $r->q('all') === '1';
        $newKey = $_SESSION['_new_api_key'] ?? null;
        unset($_SESSION['_new_api_key']);
        return $this->view('apikeys/index', [
            'title' => 'API keys',
            'keys' => (new ApiKeyRepository($this->db()))->forUser($all ? null : $this->userId()),
            'scopes' => ApiKeyRepository::SCOPES,
            'newKey' => $newKey,
            'all' => $all,
        ]);
    }

    public function store(Request $r): Response
    {
        if ($resp = $this->validate($r, ['name' => 'required|max:100', 'expires_days' => 'int|min:0|max:3650'])) {
            return $resp;
        }
        $scopes = array_values(array_intersect((array) $r->input('scopes', []), array_keys(ApiKeyRepository::SCOPES)));
        if (!$scopes) {
            return $this->failBack($r, ['scopes' => 'Pick at least one scope.']);
        }
        $days = $r->int('expires_days', 0);
        $exp = $days > 0 ? gmdate('Y-m-d H:i:s', time() + $days * 86400) : null;
        $res = (new ApiKeyRepository($this->db()))->create($this->userId(), $r->str('name'), $scopes, $exp);
        Audit::log('apikey.created', 'api_key', $res['id'], ['scopes' => $scopes]);
        $_SESSION['_new_api_key'] = $res['key'];
        return Response::redirect(url('api-keys'));
    }

    public function destroy(Request $r, string $id): Response
    {
        $ok = (new ApiKeyRepository($this->db()))->revoke((int) $id, App::auth()->can('users.manage') ? null : $this->userId());
        if ($ok) {
            Audit::log('apikey.revoked', 'api_key', $id);
            flash('success', 'API key revoked.');
        }
        return Response::redirect(url('api-keys'));
    }
}
