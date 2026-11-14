<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Outbound HTTP.
 *  - api():        calls to trusted provider APIs (HTTPS only, optional rotating proxies).
 *  - fetchPublic() / fetchMany(): pages from untrusted URLs (business websites) with SSRF protection:
 *      http/https only, ports 80/443/8080/8443, every resolved address must be public, the connection is
 *      pinned to the vetted IP (no DNS rebinding), redirects are followed manually and re-vetted,
 *      a byte cap and a hard total timeout stop slow or huge responses.
 */
final class HttpClient
{
    public const ALLOWED_PORTS = [80, 443, 8080, 8443];

    private const BLOCKED_V4 = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24',
        '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4', '255.255.255.255/32',
    ];
    private const BLOCKED_V6 = ['::/128', '::1/128', 'fc00::/7', 'fe80::/10', 'ff00::/8', '2001:db8::/32', '100::/64', '2001::/32', '2002::/16', '64:ff9b::/96', '::ffff:0:0/96'];

    private int $proxyIndex = 0;

    /** @param list<string> $proxies */
    public function __construct(private array $proxies = [], private string $userAgent = 'NextaLeadMap/1.0')
    {
        $this->userAgent = mb_substr((string) preg_replace('/[^\x20-\x7E]/', '', $this->userAgent), 0, 255); // no header injection
    }

    // ───────────────────────────── Trusted API calls ─────────────────────────────

    /**
     * @return array{status:int, body:string, headers:array<string,string>, error:string}
     */
    public function api(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): array
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            throw new RuntimeException('Provider URLs must use HTTPS');
        }
        $ch = curl_init($url);
        $respHeaders = [];
        $opts = [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_HTTPHEADER => array_map(static fn ($k, $v) => "$k: $v", array_keys($headers), $headers),
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$respHeaders): int {
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $respHeaders[strtolower(trim($k))] = trim($v);
                }
                return strlen($line);
            },
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        if ($p = $this->nextProxy()) {
            $opts[CURLOPT_PROXY] = $p;
        }
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        return ['status' => $status, 'body' => is_string($resp) ? $resp : '', 'headers' => $respHeaders, 'error' => $err];
    }

    private function nextProxy(): ?string
    {
        if (!$this->proxies) {
            return null;
        }
        $p = $this->proxies[$this->proxyIndex % count($this->proxies)];
        $this->proxyIndex++;
        return $p;
    }

    // ───────────────────────────── SSRF guard ─────────────────────────────

    public static function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            foreach (self::BLOCKED_V4 as $cidr) {
                if (self::inCidr($ip, $cidr)) {
                    return false;
                }
            }
            return true;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $bin = inet_pton($ip);
            // IPv4-mapped / compatible: judge the embedded IPv4
            if ($bin !== false && str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff")) {
                return self::isPublicIp((string) inet_ntop(substr($bin, 12)));
            }
            if ($bin !== false && str_starts_with($bin, str_repeat("\0", 12))) {
                return false; // ::a.b.c.d deprecated form, ::, ::1
            }
            foreach (self::BLOCKED_V6 as $cidr) {
                if (self::inCidr($ip, $cidr)) {
                    return false;
                }
            }
            return true;
        }
        return false;
    }

    public static function inCidr(string $ip, string $cidr): bool
    {
        [$net, $bits] = explode('/', $cidr);
        $ipBin = @inet_pton($ip);
        $netBin = @inet_pton($net);
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            return false;
        }
        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            return false;
        }
        $rem = $bits % 8;
        if ($rem === 0) {
            return true;
        }
        $mask = chr((0xFF << (8 - $rem)) & 0xFF);
        return (($ipBin[$bytes] & $mask) === ($netBin[$bytes] & $mask));
    }

    /**
     * Validate an untrusted URL and resolve it to a vetted public IP.
     * @return array{url:string, host:string, port:int, ip:string, scheme:string}
     */
    public static function vet(string $url): array
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 2048) {
            throw new RuntimeException('Invalid URL');
        }
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'http://' . $url;
        }
        $p = parse_url($url);
        $scheme = strtolower((string) ($p['scheme'] ?? ''));
        $host = strtolower(rtrim((string) ($p['host'] ?? ''), '.'));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($p['user']) || isset($p['pass'])) {
            throw new RuntimeException('URL not allowed');
        }
        $port = (int) ($p['port'] ?? ($scheme === 'https' ? 443 : 80));
        if (!in_array($port, self::ALLOWED_PORTS, true)) {
            throw new RuntimeException('Port not allowed');
        }
        $bareHost = trim($host, '[]');
        if (filter_var($bareHost, FILTER_VALIDATE_IP)) {
            $ips = [$bareHost];
        } else {
            if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal') || !str_contains($host, '.')) {
                throw new RuntimeException('Host not allowed');
            }
            $ips = self::resolve($host);
        }
        if (!$ips) {
            throw new RuntimeException('Host does not resolve');
        }
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                throw new RuntimeException('Address not public');
            }
        }
        // Prefer IPv4 for wider shared-host compatibility
        usort($ips, static fn ($a, $b) => (int) str_contains($a, ':') <=> (int) str_contains($b, ':'));
        return ['url' => self::asciiUrl($url), 'host' => $bareHost, 'port' => $port, 'ip' => $ips[0], 'scheme' => $scheme];
    }

    /** @return list<string> */
    private static function resolve(string $host): array
    {
        $ips = [];
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $ips = $v4;
        }
        $v6 = @dns_get_record($host, DNS_AAAA);
        if (is_array($v6)) {
            foreach ($v6 as $r) {
                if (!empty($r['ipv6'])) {
                    $ips[] = $r['ipv6'];
                }
            }
        }
        return array_values(array_unique($ips));
    }

    private static function asciiUrl(string $url): string
    {
        return (string) preg_replace_callback('/[^\x21-\x7E]/', static fn ($m) => rawurlencode($m[0]), $url);
    }

    /** One untrusted page; '' on any failure. */
    public function fetchPublic(string $url, int $timeout = 10, int $hardTimeout = 25, int $maxBytes = 400000): string
    {
        $out = '';
        $this->fetchMany([$url], 1, $timeout, $hardTimeout, $maxBytes, static function ($u, string $body) use (&$out) {
            $out = $body;
        });
        return $out;
    }

    /**
     * Fetch many untrusted URLs concurrently. $onDone(string $originalUrl, string $body, string $finalUrl, string $status).
     * status: ok | blocked | error | timeout
     */
    public function fetchMany(array $urls, int $concurrency, int $timeout, int $hardTimeout, int $maxBytes, callable $onDone, ?callable $shouldStop = null, bool $viaProxy = false): void
    {
        $queue = [];
        foreach (array_values(array_unique($urls)) as $u) {
            $queue[] = ['orig' => $u, 'url' => $u, 'hops' => 0];
        }
        $mh = curl_multi_init();
        $active = [];
        $buffers = [];

        $start = function (array $item) use (&$active, &$buffers, $mh, $timeout, $hardTimeout, $maxBytes, $onDone, $viaProxy): void {
            try {
                $v = self::vet($item['url']);
            } catch (RuntimeException) {
                $onDone($item['orig'], '', $item['url'], 'blocked');
                return;
            }
            $ch = curl_init($v['url']);
            $id = spl_object_id($ch);
            $buffers[$id] = '';
            $opts = [
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
                CURLOPT_TIMEOUT => $hardTimeout,
                CURLOPT_LOW_SPEED_LIMIT => 64,
                CURLOPT_LOW_SPEED_TIME => $timeout,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => $this->userAgent,
                CURLOPT_ENCODING => '',
                CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.5', 'Accept-Language: en;q=0.9'],
                CURLOPT_HEADER => false,
                CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$buffers, $id, $maxBytes): int {
                    $room = $maxBytes - strlen($buffers[$id]);
                    if ($room <= 0) {
                        return 0; // abort: cap reached
                    }
                    $buffers[$id] .= substr($chunk, 0, $room);
                    return strlen($chunk) > $room ? 0 : strlen($chunk);
                },
            ];
            if ($viaProxy && ($p = $this->nextProxy())) {
                $opts[CURLOPT_PROXY] = $p;
            } else {
                $opts[CURLOPT_PROXY] = '';
                $ip = str_contains($v['ip'], ':') ? '[' . $v['ip'] . ']' : $v['ip'];
                $opts[CURLOPT_RESOLVE] = [$v['host'] . ':' . $v['port'] . ':' . $ip];
            }
            curl_setopt_array($ch, $opts);
            curl_multi_add_handle($mh, $ch);
            $active[$id] = ['ch' => $ch, 'item' => $item, 'url' => $v['url']];
        };

        while ($queue || $active) {
            if ($shouldStop && $shouldStop()) {
                break;
            }
            while ($queue && count($active) < max(1, $concurrency)) {
                $start(array_shift($queue));
            }
            if (!$active) {
                continue;
            }
            do {
                $st = curl_multi_exec($mh, $running);
            } while ($st === CURLM_CALL_MULTI_PERFORM);
            if (curl_multi_select($mh, 0.5) === -1) {
                usleep(50000);
            }
            while ($info = curl_multi_info_read($mh)) {
                $ch = $info['handle'];
                $id = spl_object_id($ch);
                $meta = $active[$id];
                $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $redirect = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
                $errno = curl_errno($ch);
                $body = $buffers[$id] ?? '';
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
                unset($active[$id], $buffers[$id]);
                $item = $meta['item'];
                if ($code >= 300 && $code < 400 && $redirect !== '' && $item['hops'] < 4) {
                    $queue[] = ['orig' => $item['orig'], 'url' => $redirect, 'hops' => $item['hops'] + 1];
                    continue;
                }
                $capHit = $errno === CURLE_WRITE_ERROR && $body !== '';
                if ($errno !== 0 && !$capHit) {
                    $onDone($item['orig'], $body, $meta['url'], $errno === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'error');
                } elseif ($code >= 400) {
                    $onDone($item['orig'], '', $meta['url'], 'error');
                } else {
                    $onDone($item['orig'], $body, $meta['url'], 'ok');
                }
            }
        }
        foreach ($active as $a) {
            curl_multi_remove_handle($mh, $a['ch']);
            curl_close($a['ch']);
        }
        curl_multi_close($mh);
    }

    /** Download a file from a vetted HTTPS URL to $dest with a size cap (used by the updater). */
    public function downloadTo(string $url, string $dest, int $maxBytes, int $timeout = 300, int $hops = 0): void
    {
        $v = self::vet($url);
        if ($v['scheme'] !== 'https') {
            throw new RuntimeException('Downloads must use HTTPS');
        }
        $fh = fopen($dest, 'wb');
        if (!$fh) {
            throw new RuntimeException('Cannot write download');
        }
        $written = 0;
        $ch = curl_init($v['url']);
        $ip = str_contains($v['ip'], ':') ? '[' . $v['ip'] . ']' : $v['ip'];
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => [$v['host'] . ':' . $v['port'] . ':' . $ip],
            CURLOPT_PROXY => '',
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use ($fh, &$written, $maxBytes): int {
                $written += strlen($chunk);
                if ($written > $maxBytes) {
                    return 0;
                }
                return (int) fwrite($fh, $chunk);
            },
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $location = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fh);
        if ($code >= 300 && $code < 400 && $location !== '') {
            @unlink($dest);
            if ($hops >= 4) {
                throw new RuntimeException('Too many redirects while downloading');
            }
            $this->downloadTo($location, $dest, $maxBytes, $timeout, $hops + 1); // re-vetted (HTTPS + public host)
            return;
        }
        if ($written > $maxBytes) {
            @unlink($dest);
            throw new RuntimeException('Download exceeds the size limit');
        }
        if ($err !== '' || $code !== 200) {
            @unlink($dest);
            throw new RuntimeException('Download failed (HTTP ' . $code . ($err ? ', ' . $err : '') . ')');
        }
    }

    /** Small JSON GET from a vetted HTTPS URL (update manifest). */
    public function getJsonPublic(string $url, int $maxBytes = 65536): array
    {
        $v = self::vet($url);
        if ($v['scheme'] !== 'https') {
            throw new RuntimeException('The update server must use HTTPS');
        }
        $body = '';
        $status = '';
        $this->fetchMany([$url], 1, 15, 30, $maxBytes, static function ($u, $b, $f, $s) use (&$body, &$status) {
            $body = $b;
            $status = $s;
        });
        if ($status !== 'ok') {
            throw new RuntimeException('Could not reach the update server (' . $status . ')');
        }
        $d = json_decode($body, true, 16);
        if (!is_array($d)) {
            throw new RuntimeException('Update manifest is not valid JSON');
        }
        return $d;
    }
}
