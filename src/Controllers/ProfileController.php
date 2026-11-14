<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\UserRepository;
use App\Services\Audit;

final class ProfileController extends Controller
{
    public function show(Request $r): Response
    {
        return $this->view('profile/index', ['title' => 'My profile']);
    }

    public function update(Request $r): Response
    {
        if ($resp = $this->validate($r, ['name' => 'required|max:120'])) {
            return $resp;
        }
        (new UserRepository($this->db()))->update($this->userId(), ['name' => $r->str('name')]);
        Audit::log('profile.updated', 'user', $this->userId());
        flash('success', 'Profile saved.');
        return Response::redirect(url('profile'));
    }

    public function password(Request $r): Response
    {
        $min = max(8, (int) setting('security.password_min_length', 10));
        if ($resp = $this->validate($r, ['current_password' => 'required', 'password' => "required|min:$min|max:200|password", 'password_confirmation' => 'required|same:password'])) {
            return $resp;
        }
        $repo = new UserRepository($this->db());
        if (!App::auth()->verifyCurrentPassword((string) $r->input('current_password'), 'password_change')) {
            return $this->failBack($r, ['current_password' => 'Your current password is incorrect (or too many attempts — wait 15 minutes).']);
        }
        $repo->update($this->userId(), ['password' => (string) $r->input('password')]);
        $fresh = $repo->find($this->userId());
        Session::regenerate();
        $_SESSION['pwd_at'] = $fresh['password_changed_at'] ?? '';
        Audit::log('profile.password_changed', 'user', $this->userId());
        flash('success', 'Password changed. Other sessions were signed out.');
        return Response::redirect(url('profile'));
    }
}
