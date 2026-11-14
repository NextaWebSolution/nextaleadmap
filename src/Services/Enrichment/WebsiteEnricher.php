<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Core\HttpClient;

/**
 * Website pass: homepage, then up to N contact/about pages on the same host, all fetched concurrently
 * through the SSRF-safe client. Emails, socials, ad/tech signals, MX check, intent score.
 */
final class WebsiteEnricher
{
    private const CATEGORY_STOP = ['store', 'shop', 'service', 'services', 'company', 'center', 'centre', 'agency',
        'office', 'business', 'and', 'the', 'for', 'with'];
    private const CONTACT_HINT = '#contact|kontakt|contacto|contato|contatti|about|ueber-uns|uber-uns|impressum|team|reach-us|get-in-touch|enquir|inquir#i';

    private EmailExtractor $emails;
    private SocialExtractor $socials;
    private TechDetector $tech;
    private MxVerifier $mx;

    public function __construct(private HttpClient $http, private array $cfg)
    {
        $this->emails = new EmailExtractor($cfg['extra_junk_domains'] ?? []);
        $this->socials = new SocialExtractor();
        $this->tech = new TechDetector();
        $this->mx = new MxVerifier();
    }

    /**
     * @param list<array{id:int,title:string,phone:?string,category:?string,website:string,emails:?string,rating?:mixed,review_count?:mixed}> $rows
     * @param array{emails:bool,socials:bool,verify:bool,tech:bool} $opt
     * @return array<int,array> id => fields to save
     */
    public function enrich(array $rows, array $opt, callable $shouldStop): array
    {
        $workers = max(1, min(32, (int) ($this->cfg['workers'] ?? 8)));
        $timeout = max(3, (int) ($this->cfg['timeout'] ?? 10));
        $hard = max($timeout, (int) ($this->cfg['hard_timeout'] ?? 25));
        $maxBytes = max(50000, (int) ($this->cfg['max_bytes'] ?? 400000));
        $viaProxy = !empty($this->cfg['use_proxies']);

        $byUrl = [];
        foreach ($rows as $r) {
            $byUrl[self::normalizeUrl($r['website'])][] = $r['id'];
        }
        $pages = [];
        $status = [];
        $this->http->fetchMany(array_keys($byUrl), $workers, $timeout, $hard, $maxBytes, function ($orig, $body, $final, $st) use (&$pages, &$status) {
            $pages[$orig] = ['html' => $body, 'final' => $final];
            $status[$orig] = $st;
        }, $shouldStop, $viaProxy);

        $out = [];
        $followUps = [];
        foreach ($rows as $r) {
            $url = self::normalizeUrl($r['website']);
            $html = $pages[$url]['html'] ?? '';
            $st = $status[$url] ?? 'timeout';
            $res = ['website_status' => $html !== '' ? 'ok' : $st, 'emails' => (string) ($r['emails'] ?? '')];
            if ($html !== '' && !$this->ownsSite($html, $r)) {
                $res['website_status'] = 'not_theirs';
                $out[$r['id']] = $res;
                continue;
            }
            $host = EmailExtractor::host($pages[$url]['final'] ?? $url);
            $found = $opt['emails'] && $res['emails'] === '' ? $this->emails->find($html, $host) : [];
            $soc = $opt['socials'] ? $this->socials->find($html) : [];
            $res['_found'] = $found;
            $res['_host'] = $host;
            if ($opt['socials']) {
                $res += $soc;
            }
            if ($opt['tech']) {
                $res['tech'] = $this->tech->detect($html);
            }
            $out[$r['id']] = $res;
            $needEmail = $opt['emails'] && !$found && $res['emails'] === '';
            $needSocial = $opt['socials'] && !array_filter($soc);
            if ($html !== '' && ($needEmail || $needSocial)) {
                foreach ($this->contactLinks($html, $pages[$url]['final'] ?? $url, (int) ($this->cfg['contact_pages'] ?? 2)) as $link) {
                    $followUps[$link][] = $r['id'];
                }
            }
        }

        if ($followUps && !$shouldStop()) {
            $this->http->fetchMany(array_keys($followUps), $workers, $timeout, $hard, $maxBytes, function ($orig, $body) use (&$out, $followUps, $opt) {
                if ($body === '') {
                    return;
                }
                foreach ($followUps[$orig] as $id) {
                    if ($opt['emails'] && count($out[$id]['_found']) < 3) {
                        $more = $this->emails->find($body, $out[$id]['_host']);
                        $out[$id]['_found'] = array_slice(array_values(array_unique(array_merge($out[$id]['_found'], $more))), 0, 3);
                    }
                    if ($opt['socials']) {
                        foreach ($this->socials->find($body) as $net => $link) {
                            if ($link !== '' && empty($out[$id][$net])) {
                                $out[$id][$net] = $link;
                            }
                        }
                    }
                }
            }, $shouldStop, $viaProxy);
        }

        $rowsById = array_column($rows, null, 'id');
        foreach ($out as $id => &$res) {
            $found = $res['_found'] ?? [];
            if ($opt['verify'] && $found) {
                $kept = [];
                $hostLabel = '';
                foreach ($found as $e) {
                    $h = $this->mx->host(substr($e, strrpos($e, '@') + 1));
                    if ($h !== 'nxdomain') {
                        $kept[] = $e;
                        $hostLabel = $hostLabel ?: $h;
                    }
                }
                $found = $kept;
                $res['email_host'] = $hostLabel;
            }
            if ($found) {
                $res['emails'] = implode(', ', $found);
            }
            if ($opt['tech']) {
                $res['intent_score'] = !empty($res['tech']) ? TechDetector::intentScore($res['tech'], $rowsById[$id] ?? [], ($res['emails'] ?? '') !== '') : null;
            }
            unset($res['_found'], $res['_host']);
        }
        unset($res);
        return $out;
    }

    public static function normalizeUrl(string $u): string
    {
        $u = trim($u);
        if (!preg_match('#^https?://#i', $u)) {
            $u = 'http://' . $u;
        }
        // Drop campaign tags Google Business Profile links carry
        $p = parse_url($u);
        if (!empty($p['query'])) {
            parse_str($p['query'], $q);
            $q = array_filter($q, static fn ($k) => !preg_match('/^(utm_|gclid|fbclid|y_source)/i', (string) $k), ARRAY_FILTER_USE_KEY);
            $u = strtok($u, '?') . ($q ? '?' . http_build_query($q) : '');
        }
        return $u;
    }

    /** False only when the homepage plainly belongs to someone else (neither phone nor a category word appears). */
    public function ownsSite(string $html, array $row): bool
    {
        $html = substr($html, 0, 400000);
        $text = (string) preg_replace('#<(script|style)\b[^>]{0,1000}>.*?</\1>|<[^>]{0,2000}>#is', ' ', $html);
        $text = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
        if (mb_strlen($text) < 200) {
            return true;
        }
        $digits = (string) preg_replace('/\D/', '', (string) ($row['phone'] ?? ''));
        preg_match_all('/[^\W\d_]{4,}/u', mb_strtolower((string) ($row['category'] ?? '')), $m);
        $stems = [];
        foreach ($m[0] as $w) {
            if (!in_array($w, self::CATEGORY_STOP, true)) {
                $stems[mb_substr($w, 0, 4)] = true;
            }
        }
        if (strlen($digits) < 7 && !$stems) {
            return true;
        }
        if (strlen($digits) >= 7 && str_contains((string) preg_replace('/\D/', '', $html), substr($digits, -7))) {
            return true;
        }
        foreach (array_keys($stems) as $s) {
            if (str_contains($text, $s)) {
                return true;
            }
        }
        return false;
    }

    /** Same-host links that look like contact/about pages, absolute. */
    private function contactLinks(string $html, string $base, int $max): array
    {
        if ($max <= 0 || !preg_match_all('#<a\s[^>]{0,1000}?href\s*=\s*["\']([^"\']{1,2000})["\'][^>]{0,1000}>(.{0,500}?)</a>#is', $html, $m, PREG_SET_ORDER)) {
            return [];
        }
        $baseHost = EmailExtractor::host($base);
        $links = [];
        foreach ($m as $a) {
            $href = html_entity_decode(trim($a[1]), ENT_QUOTES | ENT_HTML5);
            if (!preg_match(self::CONTACT_HINT, $href . ' ' . strip_tags($a[2])) || preg_match('#^(mailto:|tel:|javascript:|\#)#i', $href)) {
                continue;
            }
            $abs = self::absolute($href, $base);
            if ($abs && EmailExtractor::host($abs) === $baseHost) {
                $abs = strtok($abs, '#');
                $links[$abs] = true;
            }
            if (count($links) >= $max) {
                break;
            }
        }
        return array_keys($links);
    }

    public static function absolute(string $href, string $base): ?string
    {
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        $b = parse_url($base);
        if (empty($b['host'])) {
            return null;
        }
        $scheme = $b['scheme'] ?? 'http';
        $origin = $scheme . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
        if (str_starts_with($href, '//')) {
            return $scheme . ':' . $href;
        }
        if (str_starts_with($href, '/')) {
            return $origin . $href;
        }
        $dir = isset($b['path']) ? preg_replace('#/[^/]*$#', '/', $b['path']) : '/';
        return $origin . ($dir ?: '/') . $href;
    }
}
