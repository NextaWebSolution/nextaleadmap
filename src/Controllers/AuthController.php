<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

final class AuthController extends Controller
{
    public function showLogin(Request $r): Response
    {
        if (App::$user) {
            return Response::redirect(url('/'));
        }
        return Response::html(\App\Core\View::render('auth/login', ['title' => 'Sign in'], 'layouts/auth'))->header('X-Robots-Tag', 'noindex');
    }

    public function login(Request $r): Response
    {
        $email = $r->str('email', '', 190);
        $password = (string) $r->input('password', '');
        if ($email === '' || $password === '' || strlen($password) > 1024) {
            Session::flashInput(['email' => $email], ['email' => 'Enter your email and password.']);
            return Response::redirect(url('login'));
        }
        $res = App::auth()->attempt($r, $email, $password);
        if (!$res['ok']) {
            Session::flashInput(['email' => $email], ['email' => $res['error']]);
            return Response::redirect(url('login'));
        }
        $to = $_SESSION['_intended'] ?? '/';
        unset($_SESSION['_intended']);
        if (!is_string($to) || !preg_match('#^/[A-Za-z0-9/_.-]{0,200}$#', $to) || str_contains($to, '//')) {
            $to = '/';
        }
        return Response::redirect(url($to));
    }

    public function logout(Request $r): Response
    {
        App::auth()->logout();
        Session::start($r);
        flash('success', 'You have been signed out.');
        return Response::redirect(url('login'));
    }
}
