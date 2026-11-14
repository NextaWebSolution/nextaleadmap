<?php

declare(strict_types=1);

namespace App\Services\Export;

use App\Repositories\PlaceRepository;

/** CSV / JSON exports with the kit's spreadsheet formula-injection guard. */
final class Exporter
{
    public const LEAD_COLUMNS = ['title', 'phone', 'emails', 'website', 'category', 'address', 'rating', 'review_count'];
    public const FULL_COLUMNS = ['title', 'category', 'categories', 'address', 'phone', 'website', 'emails', 'email_host',
        'instagram', 'facebook', 'linkedin', 'tiktok', 'youtube', 'x', 'rating', 'review_count', 'latitude', 'longitude',
        'price_level', 'business_status', 'opening_hours', 'plus_code', 'maps_url', 'provider_place_id', 'keyword',
        'website_status', 'intent_score', 'fb_pixel', 'google_ads_tag', 'analytics', 'bing_ads', 'linkedin_insight', 'tiktok_pixel', 'cms', 'mobile_friendly',
        'lead_status', 'notes', 'created_at'];

    /** A cell a spreadsheet would run as a formula gets a leading ' ("+1 512-555-0100" stays a phone). */
    public static function safe(mixed $v): string
    {
        $s = $v === null ? '' : (string) $v;
        return preg_match('/^\'*(?:[=@\t\r]|[+-](?![\d\s().-]*(?:(?:ext\.?|x)\s*\d+)?$))/i', $s) ? "'" . $s : $s;
    }

    public static function flatten(array $r): array
    {
        $tech = json_decode((string) ($r['tech'] ?? ''), true) ?: [];
        foreach (['fb_pixel', 'google_ads_tag', 'analytics', 'bing_ads', 'linkedin_insight', 'tiktok_pixel', 'cms', 'mobile_friendly'] as $k) {
            $r[$k] = $tech[$k] ?? '';
        }
        return $r;
    }

    /** Columns for a "leads" export: the 8 lead fields plus whichever social/email_host columns have data. */
    public static function leadColumns(bool $socials, bool $emailHost): array
    {
        return array_merge(self::LEAD_COLUMNS, $socials ? PlaceRepository::SOCIALS : [], $emailHost ? ['email_host'] : []);
    }

    /** @param resource $out */
    public static function csvHeader($out, array $cols): void
    {
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel opens accents correctly
        fputcsv($out, $cols, ',', '"', '');
    }

    /** @param resource $out */
    public static function csvRow($out, array $row, array $cols): void
    {
        $row = self::flatten($row);
        fputcsv($out, array_map(static fn ($c) => self::safe($row[$c] ?? ''), $cols), ',', '"', '');
    }

    public static function jsonRow(array $row): array
    {
        $row = self::flatten($row);
        $out = [];
        foreach (self::FULL_COLUMNS as $c) {
            $out[$c] = $row[$c] ?? null;
        }
        $out['id'] = (int) $row['id'];
        $out['job_id'] = $row['job_id'];
        return $out;
    }
}
