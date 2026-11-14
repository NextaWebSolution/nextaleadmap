<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\AuditRepository;

final class AuditController extends Controller
{
    public function index(Request $r): Response
    {
        $date = static fn ($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
        $f = ['action' => $r->q('action'), 'user' => ctype_digit($r->q('user')) ? $r->q('user') : '', 'from' => $date($r->q('from')), 'to' => $date($r->q('to'))];
        $page = $this->page($r);
        $per = $this->perPage($r);
        $res = (new AuditRepository($this->db()))->paginate($f, $page, $per);
        $users = $this->db()->all('SELECT id, name FROM {users} ORDER BY name');
        return $this->view('audit/index', ['title' => 'Audit log', 'rows' => $res['rows'], 'filters' => $f, 'users' => $users, 'pg' => self::pagination($res['total'], $page, $per)]);
    }
}
