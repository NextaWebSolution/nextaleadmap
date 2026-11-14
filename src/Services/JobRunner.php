<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\HttpClient;
use App\Repositories\JobRepository;
use App\Repositories\PlaceRepository;
use App\Services\Enrichment\WebsiteEnricher;
use App\Services\Providers\ProviderException;
use App\Services\Providers\ProviderFactory;
use App\Services\Providers\SearchQuery;
use Throwable;

/**
 * Runs one job in resumable steps. Progress (the cursor into keyword × tile searches, the deep-scan queue,
 * and the enrichment position) is saved after every batch, so a job interrupted by a cron time budget,
 * a crash or a server restart continues where it stopped. Inserts are idempotent (unique job+place key).
 */
final class JobRunner
{
    private const ENRICH_MIN_SECONDS = 60;
    private const ENRICH_CHUNK = 40;
    private const MAX_DEEP_LEVEL = 2;

    private JobRepository $jobs;
    private PlaceRepository $places;
    private float $lastCancelCheck = 0;
    private bool $cancelled = false;

    public function __construct(private ?\Closure $log = null)
    {
        $this->jobs = new JobRepository(App::db());
        $this->places = new PlaceRepository(App::db());
    }

    private function log(string $m): void
    {
        if ($this->log) {
            ($this->log)($m);
        }
    }

    /** @return string final state: done|failed|cancelled|yielded */
    public function run(array $job, int $budgetSeconds): string
    {
        $id = $job['id'];
        $p = $job['params'];
        $prog = $job['progress'] ?: [];
        $prog['deadline'] ??= time() + (int) ($p['max_time'] ?? 900);
        $prog['phase'] ??= 'search';
        $prog['cursor'] ??= 0;
        $prog['extra'] ??= [];
        $prog['warnings'] ??= [];
        $prog['enrich_after'] ??= 0;
        $budgetEnd = time() + max(10, $budgetSeconds);
        $this->cancelled = false;

        $cells = $this->cells($p);
        $keywords = $p['keywords'];
        $base = count($keywords) * count($cells);
        $deadlineHit = false;

        $stop = function () use ($id, &$prog, $budgetEnd, &$deadlineHit): bool {
            if (microtime(true) - $this->lastCancelCheck > 2) {
                $this->lastCancelCheck = microtime(true);
                $this->cancelled = $this->cancelled || $this->jobs->cancelRequested($id);
            }
            if (time() > (int) $prog['deadline']) {
                $deadlineHit = true;
            }
            return $this->cancelled || $deadlineHit || time() > $budgetEnd;
        };

        try {
            // ─── Phase 1: searches ───
            if ($prog['phase'] === 'search') {
                $provider = ProviderFactory::make($job['provider']);
                $exclude = $p['exclude_jobs'] ?? [];
                $failStreak = 0;
                while (!$stop()) {
                    $total = $base + count($prog['extra']);
                    $i = (int) $prog['cursor'];
                    if ($i >= $total) {
                        break;
                    }
                    if ($i < $base) {
                        $kwIndex = intdiv($i, count($cells));
                        $cell = $cells[$i % count($cells)];
                    } else {
                        ['k' => $kwIndex, 'cell' => $cell] = $prog['extra'][$i - $base];
                    }
                    $keyword = $keywords[$kwIndex];
                    $prog['total'] = $total;
                    $prog['message'] = 'Searching "' . $keyword . '"' . ($cell && count($cells) > 1 ? ' — tile ' . (($i % count($cells)) + 1) . '/' . count($cells) : '') . ($i >= $base ? ' (deep scan)' : '');
                    $prog['percent'] = (int) floor(($i / max(1, $total)) * ($this->wantsEnrichment($p) ? 70 : 100));
                    $this->jobs->heartbeat($id, $prog, 'running');

                    $q = new SearchQuery(
                        $keyword,
                        $cell['lat'] ?? null,
                        $cell['lon'] ?? null,
                        (int) ($cell['radius'] ?? 5000),
                        (int) ($cell['zoom'] ?? 14),
                        $p['lang'] ?? 'en',
                        $p['country'] ?? '',
                        (int) $p['max_per_search'],
                    );
                    $found = 0;
                    try {
                        foreach ($provider->search($q, $stop) as $batch) {
                            $rows = $batch['places'];
                            $found += count($rows);
                            $filterBox = $cell && (count($cells) > 1 || ($cell['level'] ?? 0) > 0);
                            $rows = array_values(array_filter($rows, static function ($r) use ($p, $cell, $filterBox) {
                                if ($r['title'] === '') {
                                    return false;
                                }
                                if (!empty($p['only_without_website']) && !empty($r['website'])) {
                                    return false;
                                }
                                return !$filterBox || GeoService::inBbox($r, $cell['bbox']);
                            }));
                            if ($exclude && $rows) {
                                $seen = $this->places->existingKeys(array_column($rows, 'place_key'), $exclude);
                                $rows = array_values(array_filter($rows, static fn ($r) => !isset($seen[$r['place_key']])));
                            }
                            $this->places->upsertMany($id, $keyword, $rows);
                            $this->jobs->bump($id, (int) $batch['calls']);
                            $this->jobs->heartbeat($id, $prog);
                        }
                        $failStreak = 0;
                    } catch (ProviderException $e) {
                        if ($e->fatal) {
                            throw $e;
                        }
                        $failStreak++;
                        $prog['warnings'][] = mb_substr($e->getMessage(), 0, 300);
                        if ($failStreak >= 3) {
                            throw new ProviderException('Stopped after 3 failed searches in a row: ' . $e->getMessage());
                        }
                    }
                    if ($this->cancelled || $deadlineHit || time() > $budgetEnd) {
                        // A search cut short by the budget is redone next run; by cancel/deadline it is abandoned.
                        if (!$this->cancelled && !$deadlineHit) {
                            break;
                        }
                    }
                    if (!empty($p['deep_scan']) && $cell && $found >= $provider->saturation() && ($cell['level'] ?? 0) < self::MAX_DEEP_LEVEL) {
                        foreach (GeoService::split($cell) as $sub) {
                            $prog['extra'][] = ['k' => $kwIndex, 'cell' => $sub];
                        }
                        $this->log("tile saturated ($found) → split into 4");
                    }
                    $prog['cursor'] = $i + 1;
                    $this->jobs->heartbeat($id, $prog);
                }
                if ($this->cancelled) {
                    return $this->end($id, 'cancelled', null, $prog);
                }
                if (!$deadlineHit && (int) $prog['cursor'] < $base + count($prog['extra'])) {
                    return $this->yieldJob($id, $prog);
                }
                if ($deadlineHit) {
                    $prog['warnings'][] = 'Max time reached: ' . $prog['cursor'] . ' of ' . ($base + count($prog['extra'])) . ' searches done.';
                    $prog['deadline'] = time() + self::ENRICH_MIN_SECONDS;
                    $deadlineHit = false;
                }
                $prog['phase'] = $this->wantsEnrichment($p) ? 'enrich' : 'done';
                $this->jobs->heartbeat($id, $prog);
            }

            // ─── Phase 2: website enrichment ───
            if ($prog['phase'] === 'enrich') {
                $cfg = [
                    'workers' => (int) setting('enrichment.workers', 8), 'timeout' => (int) setting('enrichment.timeout', 10),
                    'hard_timeout' => (int) setting('enrichment.hard_timeout', 25), 'max_bytes' => (int) setting('enrichment.max_bytes', 400000),
                    'contact_pages' => (int) setting('enrichment.contact_pages', 2), 'use_proxies' => (bool) setting('enrichment.use_proxies', false),
                    'extra_junk_domains' => (array) setting('enrichment.extra_junk_domains', []),
                ];
                $http = new HttpClient($cfg['use_proxies'] ? ProviderFactory::proxies() : [], (string) setting('enrichment.user_agent', 'Mozilla/5.0 (compatible; NextaLeadMap)'));
                $enricher = new WebsiteEnricher($http, $cfg);
                $opt = ['emails' => (bool) $p['emails'], 'socials' => (bool) $p['socials'], 'verify' => (bool) $p['verify_emails'], 'tech' => (bool) $p['tech']];
                $prog['enrich_total'] ??= (int) App::db()->value("SELECT COUNT(*) FROM {places} WHERE job_id = ? AND website IS NOT NULL AND website <> '' AND enriched_at IS NULL", [$id]);
                $prog['enrich_done'] ??= 0;
                while (!$stop()) {
                    $rows = $this->places->forEnrichment($id, (int) $prog['enrich_after'], self::ENRICH_CHUNK);
                    if (!$rows) {
                        break;
                    }
                    $prog['message'] = 'Checking websites ' . $prog['enrich_done'] . '/' . $prog['enrich_total'];
                    $prog['percent'] = 70 + (int) floor(29 * $prog['enrich_done'] / max(1, $prog['enrich_total']));
                    $this->jobs->heartbeat($id, $prog, 'enriching');
                    $res = $enricher->enrich($rows, $opt, $stop);
                    foreach ($rows as $r) {
                        if (isset($res[$r['id']])) {
                            $this->places->saveEnrichment((int) $r['id'], $res[$r['id']]);
                        }
                    }
                    if ($stop()) {
                        break; // unfinished chunk is re-run next time (rows without enriched_at)
                    }
                    $prog['enrich_after'] = (int) end($rows)['id'];
                    $prog['enrich_done'] += count($rows);
                }
                if ($this->cancelled) {
                    return $this->end($id, 'cancelled', null, $prog);
                }
                if (!$deadlineHit && $this->places->forEnrichment($id, (int) $prog['enrich_after'], 1)) {
                    return $this->yieldJob($id, $prog);
                }
                if ($deadlineHit) {
                    $left = (int) $prog['enrich_total'] - (int) $prog['enrich_done'];
                    if ($left > 0) {
                        $prog['warnings'][] = "$left website(s) not checked: ran out of time.";
                    }
                }
                $prog['phase'] = 'done';
            }
            return $this->end($id, 'done', null, $prog);
        } catch (Throwable $e) {
            $ref = bin2hex(random_bytes(4));
            App::logger()->error('job failed', ['ref' => $ref, 'job' => $id, 'type' => $e::class, 'e' => $e->getMessage(), 'at' => $e->getFile() . ':' . $e->getLine()]);
            // Provider messages are safe and actionable; anything else (SQL, paths) stays in the server log only.
            $msg = $e instanceof ProviderException ? \App\Core\Logger::redact($e->getMessage()) : 'Internal error while running the job (reference ' . $ref . '). An administrator can find details in storage/logs.';
            return $this->end($id, 'failed', $msg, $prog);
        }
    }

    private function wantsEnrichment(array $p): bool
    {
        return !empty($p['emails']) || !empty($p['socials']) || !empty($p['tech']);
    }

    /** Tiles for the job: none (keyword only), one tile, or an N×N grid over the bbox. */
    private function cells(array $p): array
    {
        $loc = $p['location'] ?? [];
        if (empty($loc['bbox'])) {
            return [null];
        }
        if ((int) ($p['grid'] ?? 1) <= 1) {
            $c = GeoService::gridCells($loc['bbox'], 1)[0];
            $c['lat'] = $loc['lat'] ?? $c['lat'];
            $c['lon'] = $loc['lon'] ?? $c['lon'];
            return [$c];
        }
        return GeoService::gridCells($loc['bbox'], (int) $p['grid']);
    }

    private function yieldJob(string $id, array $prog): string
    {
        $prog['message'] = ($prog['message'] ?? '') . ' — continuing in the next worker run';
        App::db()->query("UPDATE {jobs} SET status = 'pending', attempts = GREATEST(attempts - 1, 0), locked_by = NULL, progress = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?",
            [json_encode($prog, JSON_UNESCAPED_UNICODE), $id]);
        return 'yielded';
    }

    private function end(string $id, string $status, ?string $error, array $prog): string
    {
        $prog['percent'] = $status === 'done' ? 100 : (int) ($prog['percent'] ?? 0);
        $prog['message'] = match ($status) {
            'done' => 'Finished',
            'cancelled' => 'Cancelled',
            default => 'Failed',
        };
        $this->jobs->finish($id, $status, $error, array_values(array_unique($prog['warnings'] ?? [])), $prog);
        $this->log("job $id → $status" . ($error ? ": $error" : ''));
        return $status;
    }
}
