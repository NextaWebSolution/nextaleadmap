<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\JobRepository;
use App\Repositories\PlaceRepository;
use App\Services\Audit;
use App\Services\Export\Exporter;
use App\Services\JobService;
use App\Services\Providers\ProviderFactory;

final class JobController extends Controller
{
    private function repo(): JobRepository
    {
        return new JobRepository($this->db());
    }

    public function index(Request $r): Response
    {
        $page = $this->page($r);
        $per = $this->perPage($r);
        $f = ['status' => in_array($r->q('status'), ['pending', 'running', 'enriching', 'done', 'failed', 'cancelled'], true) ? $r->q('status') : '', 'q' => $r->q('q')];
        $res = $this->repo()->paginate($this->scope(), $f, $page, $per);
        return $this->view('jobs/index', ['title' => 'Search jobs', 'jobs' => $res['rows'], 'filters' => $f, 'pg' => self::pagination($res['total'], $page, $per)]);
    }

    public function create(Request $r): Response
    {
        $types = json_decode((string) file_get_contents(base_path('database/presets/business-types.json')), true) ?: [];
        return $this->view('jobs/create', [
            'title' => 'New search',
            'providers' => ProviderFactory::enabled(),
            'providerMeta' => ProviderFactory::PROVIDERS,
            'types' => $types,
            'previousJobs' => $this->repo()->listForSelect($this->scope(), 100),
        ]);
    }

    public function store(Request $r): Response
    {
        $in = (array) $r->input();
        $res = (new JobService())->create($in, $this->userId());
        if (!$res['ok']) {
            Session::flashInput($in, $res['errors']);
            flash('error', 'Please fix the highlighted fields.');
            return Response::redirect(url('jobs/create'));
        }
        flash('success', 'Search queued. Results appear as the worker processes it.');
        return Response::redirect(url('jobs/' . $res['id']));
    }

    public function show(Request $r, string $id): Response
    {
        $job = $this->repo()->find($id, $this->scope());
        if (!$job) {
            return \App\Core\View::error(404, 'Job not found');
        }
        $leads = (new PlaceRepository($this->db()))->search($this->scope(), ['job' => $id, 'sort' => $r->q('sort')], $this->page($r), 25);
        return $this->view('jobs/show', ['title' => $job['name'], 'job' => $job, 'leads' => $leads['rows'], 'pg' => self::pagination($leads['total'], $this->page($r), 25)]);
    }

    public function status(Request $r, string $id): Response
    {
        $job = $this->repo()->find($id, $this->scope());
        return $job ? Response::json(JobService::present($job)) : Response::error('Not found', 404);
    }

    public function cancel(Request $r, string $id): Response
    {
        $job = $this->repo()->find($id, $this->scope());
        if (!$job) {
            return Response::error('Not found', 404);
        }
        if (in_array($job['status'], ['pending', 'running', 'enriching'], true)) {
            $this->repo()->requestCancel($id);
            Audit::log('job.cancelled', 'job', $id);
            flash('success', 'Cancellation requested.');
        }
        return $r->wantsJson() ? Response::json(['ok' => true]) : Response::redirect(url('jobs/' . $id));
    }

    public function retry(Request $r, string $id): Response
    {
        $job = $this->repo()->find($id, $this->scope());
        if (!$job) {
            return Response::error('Not found', 404);
        }
        if (in_array($job['status'], ['failed', 'cancelled', 'done'], true)) {
            $p = $job['progress'];
            unset($p['deadline']);
            if ($job['status'] === 'done') {
                $p = [];
            }
            $p['message'] = 'Queued again';
            $this->db()->update('jobs', ['progress' => json_encode($p, JSON_UNESCAPED_UNICODE)], 'id = ?', [$id]);
            $this->repo()->retry($id);
            Audit::log('job.retried', 'job', $id);
            flash('success', $job['status'] === 'done' ? 'Job re-queued; new places will be added.' : 'Job re-queued. It resumes where it stopped.');
        }
        return Response::redirect(url('jobs/' . $id));
    }

    public function destroy(Request $r, string $id): Response
    {
        $job = $this->repo()->find($id, $this->scope());
        if (!$job) {
            return Response::error('Not found', 404);
        }
        if (in_array($job['status'], ['running', 'enriching'], true)) {
            flash('error', 'Cancel the job before deleting it.');
            return Response::redirect(url('jobs/' . $id));
        }
        $this->repo()->delete($id);
        Audit::log('job.deleted', 'job', $id, ['name' => $job['name']]);
        flash('success', 'Job and its leads deleted.');
        return Response::redirect(url('jobs'));
    }

    public function download(Request $r, string $id, string $format): Response
    {
        if ($resp = $this->throttle($r, 'export', 120, 3600)) {
            return $resp;
        }
        $job = $this->repo()->find($id, $this->scope());
        if (!$job) {
            return $r->wantsJson() ? Response::error('Not found', 404) : \App\Core\View::error(404, 'Job not found');
        }
        Audit::log('job.downloaded', 'job', $id, ['format' => $format]);
        return self::export($job, $format);
    }

    /** Shared by web + API: leads.csv | full.csv | json */
    public static function export(array $job, string $format): Response
    {
        $places = new PlaceRepository(App::db());
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($job['name'])), '-') ?: 'job';
        $base = $slug . '-' . substr($job['id'], 0, 8);
        $filter = ['job' => $job['id']];
        $p = $job['params'];
        if ($format === 'json') {
            return Response::download($base . '.json', 'application/json; charset=UTF-8', static function ($out) use ($places, $filter, $job) {
                fwrite($out, '{"job":' . json_encode(['id' => $job['id'], 'name' => $job['name'], 'status' => $job['status']], JSON_UNESCAPED_UNICODE) . ',"places":[');
                $first = true;
                $places->each(null, $filter, static function ($row) use ($out, &$first) {
                    fwrite($out, ($first ? '' : ',') . "\n" . json_encode(Exporter::jsonRow($row), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
                    $first = false;
                });
                fwrite($out, "\n]}");
            });
        }
        $cols = $format === 'full' ? Exporter::FULL_COLUMNS : Exporter::leadColumns(!empty($p['socials']), !empty($p['verify_emails']));
        return Response::download($base . ($format === 'full' ? '-full' : '-leads') . '.csv', 'text/csv; charset=UTF-8', static function ($out) use ($places, $filter, $cols) {
            Exporter::csvHeader($out, $cols);
            $places->each(null, $filter, static fn ($row) => Exporter::csvRow($out, $row, $cols));
        });
    }
}
