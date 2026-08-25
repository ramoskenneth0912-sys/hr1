<?php

namespace App\Models\Concerns;

use RuntimeException;

/**
 * Blocks all Eloquent model-level write operations for the read-only phase.
 * Any accidental save/delete throws before a single byte reaches MySQL.
 */
trait ThrowOnWrite
{
    public function save(array $options = []): bool
    {
        throw new RuntimeException(static::class.'::save() blocked: hr1_database is read-only in this phase.');
    }

    public function delete(): ?bool
    {
        throw new RuntimeException(static::class.'::delete() blocked: hr1_database is read-only in this phase.');
    }

    public function forceDelete(): mixed
    {
        throw new RuntimeException(static::class.'::forceDelete() blocked: hr1_database is read-only in this phase.');
    }

    public function restore(): int|bool
    {
        throw new RuntimeException(static::class.'::restore() blocked: hr1_database is read-only in this phase.');
    }

    public function increment($column, $amount = 1, array $extra = []): int
    {
        throw new RuntimeException(static::class.'::increment() blocked: hr1_database is read-only in this phase.');
    }

    public function decrement($column, $amount = 1, array $extra = []): int
    {
        throw new RuntimeException(static::class.'::decrement() blocked: hr1_database is read-only in this phase.');
    }
}
