<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

/** Ad pixels, analytics, CMS and mobile support + the 0-100 intent score (port of --tech). */
final class TechDetector
{
    private const TECH = [
        'fb_pixel' => '#connect\.facebook\.net/[^"\']{0,300}fbevents\.js|fbq\(#i',
        'google_ads_tag' => '#googleadservices\.com|AW-\d{6,}|googleads\.g\.doubleclick\.net#i',
        'analytics' => '#googletagmanager\.com/gtag|gtag\(|UA-\d{4,}|G-[A-Z0-9]{6,}#i',
        'bing_ads' => '#bat\.bing\.com|window\.uetq#i',
        'linkedin_insight' => '#snap\.licdn\.com|_linkedin_partner_id#i',
        'tiktok_pixel' => '#analytics\.tiktok\.com|ttq\.load\(#i',
    ];
    private const CMS = [
        'wordpress' => '#/wp-content/|/wp-includes/#i',
        'shopify' => '#cdn\.shopify\.com|shopify\.theme#i',
        'wix' => '#static\.wixstatic\.com|_wixcss|wix\.com#i',
        'squarespace' => '#static1\.squarespace|squarespace\.com#i',
        'webflow' => '#webflow\.com#i',
    ];

    public static function labels(): array
    {
        return ['fb_pixel' => 'Meta pixel', 'google_ads_tag' => 'Google Ads', 'analytics' => 'Analytics', 'bing_ads' => 'Bing Ads',
            'linkedin_insight' => 'LinkedIn Insight', 'tiktok_pixel' => 'TikTok pixel', 'cms' => 'CMS', 'mobile_friendly' => 'Mobile friendly'];
    }

    /** Empty array when the page did not load (unknown ≠ "no pixel"). */
    public function detect(string $html): array
    {
        if ($html === '') {
            return [];
        }
        $out = [];
        foreach (self::TECH as $k => $rx) {
            $out[$k] = preg_match($rx, $html) ? 'yes' : 'no';
        }
        $out['cms'] = '';
        foreach (self::CMS as $name => $rx) {
            if (preg_match($rx, $html)) {
                $out['cms'] = $name;
                break;
            }
        }
        $out['mobile_friendly'] = preg_match('#<meta[^>]{1,500}name=["\']viewport["\']#i', $html) ? 'yes' : 'no';
        return $out;
    }

    /** "Already spends on marketing" heuristic: pixels dominate, an established reachable business adds a little. */
    public static function intentScore(array $tech, array $place, bool $hasEmail): int
    {
        $s = 35 * (int) (($tech['fb_pixel'] ?? '') === 'yes') + 25 * (int) (($tech['google_ads_tag'] ?? '') === 'yes');
        $s += 10 * (int) (($tech['analytics'] ?? '') === 'yes');
        $reviews = (int) ($place['review_count'] ?? 0);
        $s += $reviews >= 50 ? 15 : ($reviews >= 10 ? 8 : 0);
        $s += 10 * (int) ((float) ($place['rating'] ?? 0) >= 4.0);
        $s += 5 * (int) $hasEmail;
        return min(100, $s);
    }
}
