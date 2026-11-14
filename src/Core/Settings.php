<?php

declare(strict_types=1);

namespace App\Core;

use App\Repositories\SettingRepository;

/**
 * JSON-based configuration: defaults live in config/app.json, overrides in the settings table
 * (managed from the admin panel). Secret values are encrypted at rest and only decrypted on demand.
 */
final class Settings
{
    private array $defaults;
    private ?array $overrides = null;

    public function __construct(string $defaultsFile, private ?SettingRepository $repo)
    {
        $this->defaults = is_file($defaultsFile) ? (json_decode((string) file_get_contents($defaultsFile), true) ?: []) : [];
    }

    private function load(): array
    {
        if ($this->overrides === null) {
            $this->overrides = $this->repo ? $this->repo->allPlain() : [];
        }
        return $this->overrides;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $o = $this->load();
        if (array_key_exists($key, $o)) {
            return $o[$key];
        }
        $node = $this->defaults;
        foreach (explode('.', $key) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return $default;
            }
            $node = $node[$part];
        }
        return $node;
    }

    public function int(string $key, int $default = 0): int
    {
        return (int) $this->get($key, $default);
    }

    public function bool(string $key, bool $default = false): bool
    {
        return filter_var($this->get($key, $default), FILTER_VALIDATE_BOOL);
    }

    /** Decrypted secret (provider API keys, proxies). */
    public function secret(string $key): string
    {
        return $this->repo ? $this->repo->secret($key) : '';
    }

    public function hasSecret(string $key): bool
    {
        return $this->repo !== null && $this->repo->hasSecret($key);
    }

    public function set(string $key, mixed $value, ?int $userId = null): void
    {
        $this->repo?->set($key, $value, false, $userId);
        $this->overrides = null;
    }

    public function setSecret(string $key, string $value, ?int $userId = null): void
    {
        $this->repo?->set($key, $value, true, $userId);
    }

    public function defaults(): array
    {
        return $this->defaults;
    }

    /** Flattened defaults + overrides (no secrets) for export. */
    public function exportable(): array
    {
        $flat = [];
        $walk = function (array $a, string $p) use (&$walk, &$flat): void {
            foreach ($a as $k => $v) {
                $key = $p === '' ? (string) $k : "$p.$k";
                if (is_array($v) && !array_is_list($v)) {
                    $walk($v, $key);
                } else {
                    $flat[$key] = $v;
                }
            }
        };
        $walk($this->defaults, '');
        return array_merge($flat, $this->load());
    }
}
