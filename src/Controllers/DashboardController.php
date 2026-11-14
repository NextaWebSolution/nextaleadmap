<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\JobRepository;
use App\Repositories\PlaceRepository;
use App\Services\Providers\ProviderFactory;

final class DashboardController extends Controller
{
    public function index(Request $r): Response
    {
        $scope = $this->scope();
        $stats = (new PlaceRepository($this->db()))->stats($scope);
        $jobs = new JobRepository($this->db());
        $byStatus = $jobs->countByStatus($scope);

        // 30-day series with zero-filled days
        $days = [];
        $tz = new \DateTimeZone('UTC');
        for ($i = 29; $i >= 0; $i--) {
            $d = (new \DateTimeImmutable("-$i days", $tz))->format('Y-m-d');
            $days[$d] = ['leads' => 0, 'emails' => 0];
        }
        foreach ($stats['daily'] as $row) {
            if (isset($days[$row['d']])) {
                $days[$row['d']] = ['leads' => (int) $row['c'], 'emails' => (int) $row['e']];
            }
        }
        $chart = [
            'labels' => array_map(static fn ($d) => date('d M', strtotime($d)), array_keys($days)),
            'leads' => array_column(array_values($days), 'leads'),
            'emails' => array_column(array_values($days), 'emails'),
            'categories' => ['labels' => array_column($stats['categories'], 'category'), 'values' => array_map('intval', array_column($stats['categories'], 'c'))],
            'statuses' => $stats['statuses'],
        ];
        return $this->view('dashboard/index', [
            'title' => 'Dashboard',
            'totals' => $stats['totals'],
            'byStatus' => $byStatus,
            'recent' => $jobs->recent($scope, 6),
            'chart' => $chart,
            'providersReady' => (bool) ProviderFactory::enabled(),
            'workerSeen' => setting('worker.last_seen'),
        ]);
    }
}
