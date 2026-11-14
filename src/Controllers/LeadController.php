<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repositories\JobRepository;
use App\Repositories\PlaceRepository;
use App\Services\Audit;
use App\Services\Export\Exporter;

final class LeadController extends Controller
{
    public static function filters(Request $r): array
    {
        $yn = static fn ($v) => in_array($v, ['yes', 'no'], true) ? $v : '';
        $job = $r->q('job');
        return [
            'q' => $r->q('q'),
            'job' => preg_match('/^[0-9a-f-]{36}$/', $job) ? $job : '',
            'status' => in_array($r->q('status'), PlaceRepository::LEAD_STATUSES, true) ? $r->q('status') : '',
            'has_email' => $yn($r->q('has_email')),
            'has_website' => $yn($r->q('has_website')),
            'has_phone' => $yn($r->q('has_phone')),
            'min_rating' => is_numeric($r->q('min_rating')) ? $r->q('min_rating') : '',
            'min_reviews' => ctype_digit($r->q('min_reviews')) ? $r->q('min_reviews') : '',
            'min_score' => ctype_digit($r->q('min_score')) ? $r->q('min_score') : '',
            'sort' => in_array($r->q('sort'), ['rating', 'reviews', 'score', 'name', 'newest'], true) ? $r->q('sort') : 'newest',
        ];
    }

    public function index(Request $r): Response
    {
        $f = self::filters($r);
        $page = $this->page($r);
        $per = $this->perPage($r);
        $res = (new PlaceRepository($this->db()))->search($this->scope(), $f, $page, $per);
        return $this->view('leads/index', [
            'title' => 'Leads',
            'leads' => $res['rows'],
            'filters' => $f,
            'jobs' => (new JobRepository($this->db()))->listForSelect($this->scope()),
            'pg' => self::pagination($res['total'], $page, $per),
        ]);
    }

    public function show(Request $r, string $id): Response
    {
        $lead = (new PlaceRepository($this->db()))->find((int) $id, $this->scope());
        if (!$lead) {
            return View::error(404, 'Lead not found');
        }
        return $this->view('leads/show', ['title' => $lead['title'], 'lead' => $lead, 'tech' => json_decode((string) $lead['tech'], true) ?: []]);
    }

    public function update(Request $r, string $id): Response
    {
        $repo = new PlaceRepository($this->db());
        $lead = $repo->find((int) $id, $this->scope());
        if (!$lead) {
            return Response::error('Not found', 404);
        }
        $data = [];
        if ($r->input('lead_status') !== null) {
            $data['lead_status'] = $r->str('lead_status', '', 20);
        }
        if ($r->input('notes') !== null) {
            $data['notes'] = $r->str('notes', '', 5000);
        }
        $repo->updateLead((int) $id, $data);
        Audit::log('lead.updated', 'lead', $id, array_intersect_key($data, ['lead_status' => 1]));
        if ($r->wantsJson()) {
            return Response::json(['ok' => true]);
        }
        flash('success', 'Lead updated.');
        return Response::back($r, 'leads/' . $id);
    }

    public function bulk(Request $r): Response
    {
        $ids = array_slice(array_map('intval', (array) $r->input('ids', [])), 0, 1000);
        $action = $r->str('action', '', 20);
        $repo = new PlaceRepository($this->db());
        if (!$ids) {
            flash('error', 'Select at least one lead.');
            return Response::back($r, 'leads');
        }
        if ($action === 'delete') {
            if (!App::auth()->can('leads.delete')) {
                return View::error(403, 'You cannot delete leads.');
            }
            $n = $repo->deleteIds($ids, $this->scope());
            Audit::log('lead.bulk_deleted', 'lead', null, ['count' => $n]);
            flash('success', "$n lead(s) deleted.");
        } elseif (in_array($action, PlaceRepository::LEAD_STATUSES, true)) {
            $n = 0;
            foreach ($ids as $id) {
                if ($repo->find($id, $this->scope())) {
                    $repo->updateLead($id, ['lead_status' => $action]);
                    $n++;
                }
            }
            Audit::log('lead.bulk_status', 'lead', null, ['count' => $n, 'status' => $action]);
            flash('success', "$n lead(s) marked " . $action . '.');
        }
        return Response::back($r, 'leads');
    }

    public function export(Request $r): Response
    {
        if ($resp = $this->throttle($r, 'export', 120, 3600)) {
            return $resp;
        }
        $f = self::filters($r);
        $format = $r->q('format') === 'json' ? 'json' : ($r->q('format') === 'full' ? 'full' : 'leads');
        $repo = new PlaceRepository($this->db());
        $scope = $this->scope();
        Audit::log('lead.exported', 'lead', null, ['format' => $format, 'filters' => array_filter($f)]);
        $name = 'leads-' . gmdate('Ymd-His');
        if ($format === 'json') {
            return Response::download($name . '.json', 'application/json; charset=UTF-8', static function ($out) use ($repo, $scope, $f) {
                fwrite($out, '[');
                $first = true;
                $repo->each($scope, $f, static function ($row) use ($out, &$first) {
                    fwrite($out, ($first ? '' : ',') . "\n" . json_encode(Exporter::jsonRow($row), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
                    $first = false;
                });
                fwrite($out, "\n]");
            });
        }
        $cols = $format === 'full' ? Exporter::FULL_COLUMNS : array_merge(Exporter::leadColumns(true, true), ['lead_status', 'intent_score']);
        return Response::download($name . '.csv', 'text/csv; charset=UTF-8', static function ($out) use ($repo, $scope, $f, $cols) {
            Exporter::csvHeader($out, $cols);
            $repo->each($scope, $f, static fn ($row) => Exporter::csvRow($out, $row, $cols));
        });
    }
}
