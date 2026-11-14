<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

/** First real profile link per network; share/login/platform links skipped. Port of find_socials(). */
final class SocialExtractor
{
    private const RE = [
        'instagram' => '#https?://(?:www\.)?instagram\.com/([A-Za-z0-9_.]+)#i',
        'facebook' => '#https?://(?:www\.|m\.|web\.)?facebook\.com/(profile\.php\?id=\d+|[A-Za-z0-9_.\-]+)#i',
        'linkedin' => '#https?://(?:[a-z]{2,3}\.)?linkedin\.com/(?:company|in|school)/([A-Za-z0-9_.\-%]+)#i',
        'tiktok' => '#https?://(?:www\.)?tiktok\.com/@([A-Za-z0-9_.]+)#i',
        'youtube' => '#https?://(?:www\.)?youtube\.com/(?:@|channel/|c/|user/)([A-Za-z0-9_.\-]+)#i',
        'x' => '#https?://(?:www\.)?(?:twitter|x)\.com/([A-Za-z0-9_]{1,15})\b#i',
    ];
    private const SKIP = ['', 'home', 'pages', 'people', 'help', 'about', 'policies', 'policy', 'legal', 'tos', 'privacy',
        'settings', 'sharer', 'sharer.php', 'tr', 'profile.php', 'plugins', 'dialog', 'intent', 'login', 'share.php', 'permalink.php', 'p',
        'reel', 'reels', 'explore', 'stories', 'tv', 'watch', 'events', 'groups', 'marketplace', 'gaming', 'photo', 'hashtag',
        'search', 'share', 'i', 'squarespace', 'wix', 'wixsite', 'shopify', 'wordpress', 'wordpressdotcom', 'godaddy', 'weebly',
        'webflow', 'hubspot', 'mailchimp', 'yelp', 'tripadvisor', 'opentable', 'doordash', 'ubereats', 'grubhub', 'toasttab', 'squareup'];

    /** @return array<string,string> */
    public function find(string $html): array
    {
        $out = array_fill_keys(array_keys(self::RE), '');
        if ($html === '') {
            return $out;
        }
        $html = str_replace('\\/', '/', $html);
        foreach (self::RE as $net => $rx) {
            if (!preg_match_all($rx, $html, $m, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($m as $hit) {
                $h = strtolower($hit[1]);
                if (in_array($h, self::SKIP, true) || ($net === 'facebook' && (ctype_digit($h) || strlen($h) < 3))) {
                    continue;
                }
                $out[$net] = rtrim(str_replace('\\', '', $hit[0]), "\"'/");
                break;
            }
        }
        return $out;
    }

    public static function networks(): array
    {
        return array_keys(self::RE);
    }
}
