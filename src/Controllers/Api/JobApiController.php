<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\Controller;
use App\Controllers\JobController;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\JobRepository;
use App\Services\Audit;
use App\Services\JobService;

final class JobApiController extends Controller
{
    private function repo(): JobRepository
    {
        return new JobRepository($this->db());
    }

    public function index(Request $r): Response
    {
        $page = $this->page($r);
        $per = $this->perPage($r);
        $status = in_array($r->q('status'), ['pending', 'running', 'enriching', 'done', 'failed', 'cancelled'], true) ? $r->q('status') : '';
        $res = $this->repo()->paginate($this->scope(), ['status' => $status, 'q' => $r->q('q')], $page, $per);
        return Response::json(['data' => array_map([JobService::class, 'present'], $res['rows']), 'meta' => self::pagination($res['total'], $page, $per)]);
    }

    public function store(Request $r): Response
    {
        if (!$r->isJson()) {
            return Response::error('Send a JSON body with Content-Type: application/json', 415);
        }
        $res = (new JobService())->create((array) $r->input(), $this->userId());
        if (!$res['ok']) {
            return Response::error('Validation failed', 422, ['fields' => $res['errors']]);
        }
        $job = $this->repo()->find($res['id']);
        return Response::json(['data' => JobService::present($job)], 201, ['Location' => url('api/v1/jobs/' . $res['id'])]);
    }

    public function show(Request $r, string $id): Response
    {
        $job = $this->repo()->find($id, $this->scope());
        return $job ? Response::json(['data' => JobService::present($job)]) : Response::error('Job not found', 404);
    }

    public function cancel(Request $r, string $id): Response
    {
        $job = $this->repo()->find($id, $this->scope());
        if (!$job) {
            return Response::error('Job not found', 404);
        }
        if (!in_array($job['status'], ['pending', 'running', 'enriching'], true)) {
            return Response::error('Job is already ' . $job['status'], 409);
        }
        $this->repo()->requestCancel($id);
        Audit::log('job.cancelled', 'job', $id);
        return Response::json(['data' => JobService::present($this->repo()->find($id))], 202);
    }

    public function destroy(Request $r, string $id): Response
    {
        $job = $this->repo()->find($id, $this->scope());
        if (!$job) {
            return Response::error('Job not found', 404);
        }
        if (in_array($job['status'], ['running', 'enriching'], true)) {
            return Response::error('Cancel the job before deleting it', 409);
        }
        $this->repo()->delete($id);
        Audit::log('job.deleted', 'job', $id);
        return new Response('', 204);
    }

    public function download(Request $r, string $id): Response
    {
        if ($resp = $this->throttle($r, 'export', 120, 3600)) {
            return $resp;
        }
        $job = $this->repo()->find($id, $this->scope());
        if (!$job) {
            return Response::error('Job not found', 404);
        }
        $format = in_array($r->q('format'), ['leads', 'full', 'json'], true) ? $r->q('format') : 'leads';
        Audit::log('job.downloaded', 'job', $id, ['format' => $format]);
        return JobController::export($job, $format);
    }
}
