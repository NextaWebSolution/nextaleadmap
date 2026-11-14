<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repositories\RoleRepository;
use App\Repositories\UserRepository;
use App\Services\Audit;

/**
 * User management. Anti-escalation rule (server-side): a manager may only create, edit or delete
 * accounts — and assign roles — whose permissions are a subset of the manager's own permissions.
 * Only a full administrator ("*") can create or manage other administrators.
 */
final class UserController extends Controller
{
    private function users(): UserRepository
    {
        return new UserRepository($this->db());
    }

    private static function rolePerms(array $role): array
    {
        $p = $role['permissions'] ?? $role['role_permissions'] ?? '[]';
        return is_array($p) ? $p : (json_decode((string) $p, true) ?: []);
    }

    /** Roles the current user is allowed to hand out. */
    private function assignableRoles(): array
    {
        return array_values(array_filter((new RoleRepository($this->db()))->all(), static fn ($r) => App::auth()->covers(self::rolePerms($r))));
    }

    /** May the current user manage this existing account? */
    private function canManage(array $target): bool
    {
        return App::auth()->covers(self::rolePerms($target));
    }

    private function denied(string $action, mixed $id = null): Response
    {
        Audit::log('security.escalation_blocked', 'user', $id, ['action' => $action]);
        return View::error(403, 'You cannot manage accounts or roles with more permissions than your own.');
    }

    public function index(Request $r): Response
    {
        $page = $this->page($r);
        $per = $this->perPage($r);
        $res = $this->users()->paginate($r->q('q'), $page, $per);
        return $this->view('users/index', ['title' => 'Users', 'users' => $res['rows'], 'q' => $r->q('q'), 'pg' => self::pagination($res['total'], $page, $per)]);
    }

    public function create(Request $r): Response
    {
        return $this->view('users/form', ['title' => 'Add user', 'user' => null, 'roles' => $this->assignableRoles()]);
    }

    public function edit(Request $r, string $id): Response
    {
        $u = $this->users()->find((int) $id);
        if (!$u) {
            return View::error(404, 'User not found');
        }
        if (!$this->canManage($u)) {
            return $this->denied('edit', $id);
        }
        return $this->view('users/form', ['title' => 'Edit user', 'user' => $u, 'roles' => $this->assignableRoles()]);
    }

    private function rules(bool $creating): array
    {
        $min = max(8, (int) setting('security.password_min_length', 10));
        return [
            'name' => 'required|max:120',
            'email' => 'required|email|max:190',
            'role_id' => 'required|int',
            'status' => 'required|in:active,disabled',
            'password' => ($creating ? 'required|' : '') . "min:$min|max:200|password",
        ];
    }

    public function store(Request $r): Response
    {
        if ($resp = $this->validate($r, $this->rules(true))) {
            return $resp;
        }
        if ($this->users()->findByEmail($r->str('email'))) {
            return $this->failBack($r, ['email' => 'A user with this email already exists.']);
        }
        $role = (new RoleRepository($this->db()))->find($r->int('role_id'));
        if (!$role) {
            return $this->failBack($r, ['role_id' => 'Choose a role.']);
        }
        if (!App::auth()->covers(self::rolePerms($role))) {
            return $this->denied('create_with_role:' . $role['slug']);
        }
        $id = $this->users()->create(['name' => $r->str('name'), 'email' => $r->str('email'), 'password' => (string) $r->input('password'), 'role_id' => (int) $role['id'], 'status' => $r->str('status')]);
        Audit::log('user.created', 'user', $id, ['email' => $r->str('email'), 'role' => $role['slug']]);
        flash('success', 'User created.');
        return Response::redirect(url('users'));
    }

    public function update(Request $r, string $id): Response
    {
        $u = $this->users()->find((int) $id);
        if (!$u) {
            return View::error(404, 'User not found');
        }
        if (!$this->canManage($u)) {
            return $this->denied('update', $id);
        }
        if ($resp = $this->validate($r, $this->rules(false))) {
            return $resp;
        }
        $existing = $this->users()->findByEmail($r->str('email'));
        if ($existing && (int) $existing['id'] !== (int) $id) {
            return $this->failBack($r, ['email' => 'Another user has this email.']);
        }
        $role = (new RoleRepository($this->db()))->find($r->int('role_id'));
        if (!$role) {
            return $this->failBack($r, ['role_id' => 'Choose a role.']);
        }
        if (!App::auth()->covers(self::rolePerms($role))) {
            return $this->denied('assign_role:' . $role['slug'], $id);
        }
        $demotingAdmin = $u['role_slug'] === 'admin' && ($role['slug'] !== 'admin' || $r->str('status') !== 'active');
        if ($demotingAdmin && $this->users()->countAdmins() <= 1) {
            return $this->failBack($r, ['role_id' => 'You cannot remove the last active administrator.']);
        }
        $this->users()->update((int) $id, ['name' => $r->str('name'), 'email' => $r->str('email'), 'role_id' => (int) $role['id'], 'status' => $r->str('status'), 'password' => (string) $r->input('password', '')]);
        Audit::log('user.updated', 'user', $id, ['role' => $role['slug'], 'status' => $r->str('status'), 'password_changed' => (string) $r->input('password', '') !== '']);
        flash('success', 'User saved.');
        return Response::redirect(url('users'));
    }

    public function destroy(Request $r, string $id): Response
    {
        $u = $this->users()->find((int) $id);
        if (!$u) {
            return View::error(404, 'User not found');
        }
        if (!$this->canManage($u)) {
            return $this->denied('delete', $id);
        }
        if ((int) $id === $this->userId()) {
            flash('error', 'You cannot delete your own account.');
            return Response::redirect(url('users'));
        }
        if ($u['role_slug'] === 'admin' && $this->users()->countAdmins() <= 1) {
            flash('error', 'You cannot delete the last administrator.');
            return Response::redirect(url('users'));
        }
        $this->db()->query('UPDATE {jobs} SET user_id = NULL WHERE user_id = ?', [(int) $id]);
        $this->users()->delete((int) $id);
        Audit::log('user.deleted', 'user', $id, ['email' => $u['email']]);
        flash('success', 'User deleted. Their jobs were kept.');
        return Response::redirect(url('users'));
    }
}
