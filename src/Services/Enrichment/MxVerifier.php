<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

/**
 * Who runs a domain's mail: google_workspace | microsoft_365 | other | none (no MX) | nxdomain | '' (lookup failed).
 * Native DNS via dns_get_record(); results cached per run.
 */
final class MxVerifier
{
    private array $cache = [];

    public function host(string $domain): string
    {
        $domain = strtolower(trim($domain, '. '));
        if ($domain === '' || !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain)) {
            return '';
        }
        if (isset($this->cache[$domain])) {
            return $this->cache[$domain];
        }
        $mx = @dns_get_record($domain, DNS_MX);
        if ($mx === false) {
            return '';
        }
        if (!$mx) {
            // No MX: does the domain exist at all?
            $any = @dns_get_record($domain, DNS_A) ?: @dns_get_record($domain, DNS_AAAA);
            $host = ($any || @dns_get_record($domain, DNS_SOA)) ? 'none' : 'nxdomain';
        } else {
            $targets = strtolower(implode(' ', array_column($mx, 'target')));
            $host = match (true) {
                str_contains($targets, 'google.com') || str_contains($targets, 'googlemail.com') => 'google_workspace',
                str_contains($targets, 'outlook.com') => 'microsoft_365',
                default => 'other',
            };
        }
        if (count($this->cache) > 4096) {
            $this->cache = [];
        }
        return $this->cache[$domain] = $host;
    }
}
