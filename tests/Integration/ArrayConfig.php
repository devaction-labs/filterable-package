<?php

declare(strict_types=1);

namespace Tests\Integration;

use ArrayAccess;

/**
 * A minimal, ArrayAccess config repository for the integration bootstrap
 * (the standalone illuminate/config package is not a dependency).
 *
 * @implements ArrayAccess<string, mixed>
 */
class ArrayConfig implements ArrayAccess
{
    /** @param array<string, mixed> $items */
    public function __construct(private array $items = []) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->items, $key, $default);
    }

    public function set(string $key, mixed $value = null): void
    {
        data_set($this->items, $key, $value);
    }

    public function has(string $key): bool
    {
        return data_get($this->items, $key) !== null;
    }

    public function offsetExists(mixed $offset): bool
    {
        return $this->has((string) $offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->get((string) $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->set((string) $offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->set((string) $offset);
    }
}
