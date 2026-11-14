<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

/** Email discovery rules ported 1:1 from the kit (engine/jobs.py + scripts/scrape.py). */
final class EmailExtractor
{
    private const EMAIL_RE = '/(?<![A-Za-z0-9._%+-])[A-Za-z0-9._%+-]{1,64}@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,24}/';
    private const JUNK_SUFFIX = ['.png', '.jpg', '.jpeg', '.gif', '.svg', '.webp', '.avif', '.css', '.js'];
    public const JUNK_DOMAIN = ['example', 'sentry', 'wixpress', 'wix.com', 'godaddy', 'squarespace', 'wordpress',
        'domain.com', 'email.com', 'yourdomain', 'mysite.com', 'schema.org', 'w3.org', 'sentry.io',
        'linktr.ee', 'linkin.bio', 'beacons.ai', 'officite',
        'gov', 'mil', 'hubspot', 'mailchimp', 'constantcontact', 'sendgrid', 'yext', 'birdeye', 'podium', 'zocdoc'];
    private const JUNK_LOCAL = ['name', 'email', 'user', 'you', 'someone', 'example', 'username', 'youremail', 'yourname',
        'firstname', 'lastname', 'sample', 'noreply', 'no-reply', 'donotreply', 'do-not-reply', 'mailer-daemon', 'postmaster',
        'abuse', 'phishing', 'spoof', 'spam', 'fraud', 'dmca', 'hostmaster'];

    /** @param list<string> $extraJunk */
    public function __construct(private array $extraJunk = [])
    {
    }

    /** "sentry.io" = domain + subdomains; "gov" (no dot) = any whole label. */
    public function junkDomain(string $dom): bool
    {
        $labels = explode('.', $dom);
        foreach (array_merge(self::JUNK_DOMAIN, $this->extraJunk) as $j) {
            $j = strtolower(trim($j));
            if ($j === '') {
                continue;
            }
            if (str_contains($j, '.') ? ($dom === $j || str_ends_with($dom, '.' . $j)) : in_array($j, $labels, true)) {
                return true;
            }
        }
        return false;
    }

    public function real(string $e): bool
    {
        $at = strrpos($e, '@');
        if ($at === false) {
            return false;
        }
        $local = substr($e, 0, $at);
        $dom = substr($e, $at + 1);
        if (!preg_match('/^[A-Za-z0-9._%+-]{1,64}@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,24}$/', $e)) {
            return false;
        }
        foreach (self::JUNK_SUFFIX as $s) {
            if (str_ends_with($e, $s)) {
                return false;
            }
        }
        return !in_array($local, self::JUNK_LOCAL, true) && !preg_match('/^[0-9a-f]{16,}$/', $local) && !$this->junkDomain($dom);
    }

    /** Up to $max addresses, the site's own domain first. */
    public function find(string $html, string $siteHost = '', int $max = 3): array
    {
        if ($html === '') {
            return [];
        }
        $text = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // mailto: links may be percent-encoded
        if (preg_match_all('/mailto:([^"\'>\s?]+)/i', $text, $mm)) {
            $text .= ' ' . implode(' ', array_map('rawurldecode', $mm[1]));
        }
        preg_match_all(self::EMAIL_RE, $text, $m);
        $found = [];
        foreach ($m[0] as $e) {
            $e = strtolower(rawurldecode($e));
            $e = (string) preg_replace('/^(?:u00(?:3c|3e|22|27|26))+/', '', $e); // JSON-escaped <, >, ", ', & glued on
            if (preg_match('/\s/', $e) || !$this->real($e) || in_array($e, $found, true)) {
                continue;
            }
            $found[] = $e;
        }
        if ($siteHost !== '') {
            usort($found, static function ($a, $b) use ($siteHost) {
                $own = static fn ($e) => str_ends_with($e, '@' . $siteHost) || str_ends_with($e, '.' . $siteHost);
                return (int) !$own($a) <=> (int) !$own($b);
            });
        }
        return array_slice($found, 0, $max);
    }

    public static function host(string $url): string
    {
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'http://' . $url;
        }
        $h = strtolower((string) parse_url($url, PHP_URL_HOST));
        return str_starts_with($h, 'www.') ? substr($h, 4) : $h;
    }
}
