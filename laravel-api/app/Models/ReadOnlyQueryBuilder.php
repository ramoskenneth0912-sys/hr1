<?php

namespace App\Models;

use RuntimeException;

/**
 * Query-builder level guard: covers Model::query()->update(), ::insert(),
 * ::upsert(), ::delete() and ::truncate() which bypass model instance methods.
 */
class ReadOnlyQueryBuilder extends \Illuminate\Database\Query\Builder
{
    public function insert(array $values): bool
    {
        throw new RuntimeException('Query-builder insert blocked: hr1_database is read-only in this phase.');
    }

    public function update(array $values): int
    {
        throw new RuntimeException('Query-builder update blocked: hr1_database is read-only in this phase.');
    }

    public function upsert(array $values, $uniqueBy, $columns = null): int
    {
        throw new RuntimeException('Query-builder upsert blocked: hr1_database is read-only in this phase.');
    }

    public function delete($id = null): int
    {
        throw new RuntimeException('Query-builder delete blocked: hr1_database is read-only in this phase.');
    }

    public function truncate(): void
    {
        throw new RuntimeException('Query-builder truncate blocked: hr1_database is read-only in this phase.');
    }
}
