<?php

declare(strict_types=1);

namespace App\Services\Providers;

use RuntimeException;

/** $fatal = configuration/auth errors that no retry will fix (bad key, billing disabled). */
final class ProviderException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $fatal = false, public readonly int $status = 0)
    {
        parent::__construct($message);
    }
}
