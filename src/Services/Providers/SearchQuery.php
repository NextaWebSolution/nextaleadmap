<?php

declare(strict_types=1);

namespace App\Services\Providers;

final class SearchQuery
{
    public function __construct(
        public readonly string $keyword,
        public readonly ?float $lat = null,
        public readonly ?float $lon = null,
        public readonly int $radius = 5000,
        public readonly int $zoom = 14,
        public readonly string $lang = 'en',
        public readonly string $country = '',
        public readonly int $limit = 20,
    ) {
    }

    public function hasPoint(): bool
    {
        return $this->lat !== null && $this->lon !== null;
    }
}
