<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\Controller;
use App\Controllers\LeadController;
use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\PlaceRepository;
use App\Services\Audit;
use App\Services\Export\Exporter;

final class LeadApiController extends Controller
{
    public function index(Request $r): Response
    {
        $page = $this->page($r);
        $per = $this->perPage($r);
        $res = (new PlaceRepository($this->db()))->search($this->scope(), LeadController::filters($r), $page, $per);
        return Response::json(['data' => $res['rows'], 'meta' => self::pagination($res['total'], $page, $per)]);
    }

    public function show(Request $r, string $id): Response
    {
        $lead = (new PlaceRepository($this->db()))->find((int) $id, $this->scope());
        if (!$lead) {
            return Response::error('Lead not found', 404);
        }
        $out = Exporter::jsonRow($lead);
        return Response::json(['data' => $out]);
    }

    public function update(Request $r, string $id): Response
    {
        $repo = new PlaceRepository($this->db());
        if (!$repo->find((int) $id, $this->scope())) {
            return Response::error('Lead not found', 404);
        }
        $status = $r->input('lead_status');
        if ($status !== null && !in_array($status, PlaceRepository::LEAD_STATUSES, true)) {
            return Response::error('Validation failed', 422, ['fields' => ['lead_status' => 'One of: ' . implode(', ', PlaceRepository::LEAD_STATUSES)]]);
        }
        $data = array_filter(['lead_status' => $status, 'notes' => $r->input('notes') !== null ? $r->str('notes', '', 5000) : null], static fn ($v) => $v !== null);
        $repo->updateLead((int) $id, $data);
        Audit::log('lead.updated', 'lead', $id, ['lead_status' => $status]);
        return Response::json(['data' => Exporter::jsonRow($repo->find((int) $id, $this->scope()))]);
    }

    public function me(Request $r): Response
    {
        $u = App::$user;
        return Response::json(['data' => ['id' => (int) $u['id'], 'name' => $u['name'], 'email' => $u['email'], 'role' => $u['role_slug'], 'scopes' => $r->attributes['scopes'] ?? []]]);
    }

    public function docs(Request $r): Response
    {
        return Response::json([
            'name' => 'NextaLeadMap REST API', 'version' => 'v1', 'auth' => 'Authorization: Bearer nlm_… (or X-API-Key header)',
            'endpoints' => [
                'GET /api/v1/me' => 'Key owner and scopes',
                'GET /api/v1/jobs' => 'List jobs (?status=&q=&page=&per_page=) [jobs:read]',
                'POST /api/v1/jobs' => 'Create a job (JSON) [jobs:write]',
                'GET /api/v1/jobs/{id}' => 'Job status and progress [jobs:read]',
                'POST /api/v1/jobs/{id}/cancel' => 'Cancel a job [jobs:write]',
                'DELETE /api/v1/jobs/{id}' => 'Delete a job and its leads [jobs:write]',
                'GET /api/v1/jobs/{id}/download?format=leads|full|json' => 'Download results [jobs:read]',
                'GET /api/v1/leads' => 'Search leads (?q=&job=&status=&has_email=yes&min_rating=&sort=) [leads:read]',
                'GET /api/v1/leads/{id}' => 'One lead [leads:read]',
                'PATCH /api/v1/leads/{id}' => 'Update lead_status / notes [leads:write]',
                'GET /api/v1/presets/cities?q=' => 'City autocomplete [jobs:read]',
                'GET /api/v1/presets/business-types' => 'Business type presets [jobs:read]',
            ],
            'create_job_example' => [
                'name' => 'Dentists Pune', 'keywords' => ['dentists'], 'provider' => 'google_places', 'location_mode' => 'city', 'city' => 'Pune, Maharashtra',
                'grid' => 2, 'deep_scan' => false, 'max_per_search' => 60, 'lang' => 'en', 'country' => 'in',
                'emails' => true, 'socials' => true, 'verify_emails' => false, 'tech' => true, 'only_without_website' => false, 'max_time' => 900,
            ],
        ]);
    }
}
