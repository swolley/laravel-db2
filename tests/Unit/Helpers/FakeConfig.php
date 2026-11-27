<?php

namespace Tests\Unit\Helpers;

use Illuminate\Support\Arr;

/**
 * Minimal stand-in for Illuminate\Config\Repository, which this package does not require.
 */
class FakeConfig
{
    /**
     * @param array<string, mixed> $items
     */
    public function __construct(private array $items = [])
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->items, $key, $default);
    }

    public function set(string $key, mixed $value): void
    {
        Arr::set($this->items, $key, $value);
    }
}
