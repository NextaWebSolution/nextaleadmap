<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repositories\RoleRepository;
use App\Services\Audit;

final class RoleController extends Controller
{
    private function roles(): RoleRepository
    {
        return new RoleRepository($this->db());
    }

    public function index(Request $r): Response
    {
        return $this->view('roles/index', ['title' => 'Roles & permissions', 'roles' => $this->roles()->all(), 'defs' => RoleRepository::definitions()['permissions']]);
    }

    public function create(Request $r): Response
    {
        return $this->view('roles/form', ['title' => 'New role', 'role' => null, 'defs' => self::grantable()]);
    }

    public function edit(Request $r, string $id): Response
    {
        $role = $this->roles()->find((int) $id);
        if (!$role) {
            return View::error(404, 'Role not found');
        }
        $role['permissions'] = json_decode((string) $role['permissions'], true) ?: [];
        if (!App::auth()->covers($role['permissions'])) {
            return $this->denied('edit_role', $id);
        }
        return $this->view('roles/form', ['title' => 'Edit role', 'role' => $role, 'defs' => self::grantable()]);
    }

    /** Permission catalogue limited to what the current user may grant. */
    private static function grantable(): array
    {
        return array_filter(RoleRepository::definitions()['permissions'], static fn ($p) => App::auth()->covers([$p]), ARRAY_FILTER_USE_KEY);
    }

    private function denied(string $action, mixed $id = null): Response
    {
        Audit::log('security.escalation_blocked', 'role', $id, ['action' => $action]);
        return View::error(403, 'You can only grant permissions you hold yourself.');
    }

    private function perms(Request $r): array
    {
        $valid = array_keys(RoleRepository::definitions()['permissions']);
        return array_values(array_intersect((array) $r->input('permissions', []), $valid));
    }

    public function store(Request $r): Response
    {
        if ($resp = $this->validate($r, ['name' => 'required|max:100', 'slug' => 'required|regex:/^[a-z][a-z0-9_-]{1,49}$/', 'description' => 'max:255'])) {
            return $resp;
        }
        if ($this->roles()->findBySlug($r->str('slug'))) {
            return $this->failBack($r, ['slug' => 'This slug is taken.']);
        }
        if (!App::auth()->covers($this->perms($r))) {
            return $this->denied('create_role');
        }
        $id = $this->roles()->save(null, $r->str('slug'), $r->str('name'), $r->str('description'), $this->perms($r));
        Audit::log('role.created', 'role', $id, ['permissions' => $this->perms($r)]);
        flash('success', 'Role created.');
        return Response::redirect(url('roles'));
    }

    public function update(Request $r, string $id): Response
    {
        $role = $this->roles()->find((int) $id);
        if (!$role) {
            return View::error(404, 'Role not found');
        }
        if (!App::auth()->covers(json_decode((string) $role['permissions'], true) ?: []) || !App::auth()->covers($this->perms($r))) {
            return $this->denied('update_role', $id);
        }
        if ($resp = $this->validate($r, ['name' => 'required|max:100', 'description' => 'max:255'])) {
            return $resp;
        }
        $perms = $role['slug'] === 'admin' ? ['*'] : $this->perms($r); // the admin role always keeps full access
        $this->roles()->save((int) $id, $role['slug'], $r->str('name'), $r->str('description'), $perms);
        Audit::log('role.updated', 'role', $id, ['permissions' => $perms]);
        flash('success', 'Role saved. Changes apply on the next request.');
        return Response::redirect(url('roles'));
    }

    public function destroy(Request $r, string $id): Response
    {
        $role = $this->roles()->find((int) $id);
        if ($role && !App::auth()->covers(json_decode((string) $role['permissions'], true) ?: [])) {
            return $this->denied('delete_role', $id);
        }
        if (!$role || $role['is_system']) {
            flash('error', 'Built-in roles cannot be deleted.');
            return Response::redirect(url('roles'));
        }
        if ((int) $this->db()->value('SELECT COUNT(*) FROM {users} WHERE role_id = ?', [(int) $id]) > 0) {
            flash('error', 'Move users to another role first.');
            return Response::redirect(url('roles'));
        }
        $this->roles()->delete((int) $id);
        Audit::log('role.deleted', 'role', $id, ['slug' => $role['slug']]);
        flash('success', 'Role deleted.');
        return Response::redirect(url('roles'));
    }
}
