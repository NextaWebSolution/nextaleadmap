<?php

declare(strict_types=1);

namespace App\Services\Providers;

use Generator;

interface ProviderInterface
{
    public function id(): string;

    public function name(): string;

    /** Most results one search can return (the provider's hard cap). */
    public function maxPerSearch(): int;

    /** A tile returning at least this many places is "saturated" and worth splitting (deep scan). */
    public function saturation(): int;

    /**
     * Run one search. Yields batches: ['places' => list<normalized place>, 'calls' => int].
     * @param callable():bool $shouldStop
     */
    public function search(SearchQuery $q, callable $shouldStop): Generator;

    /** @return array{ok:bool, message:string} */
    public function test(): array;
}
