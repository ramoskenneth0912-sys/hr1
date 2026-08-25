<?php

namespace App\Models;

use App\Models\Concerns\ThrowOnWrite;
use Illuminate\Database\Eloquent\Model;

abstract class BaseModel extends Model
{
    use ThrowOnWrite;

    /**
     * Every query-builder write verb (insert/update/upsert/delete/truncate)
     * goes through this builder — the subclass throws before any SQL runs.
     */
    protected function newBaseQueryBuilder()
    {
        $conn = $this->getConnection();

        return new ReadOnlyQueryBuilder(
            $conn,
            $conn->getQueryGrammar(),
            $conn->getPostProcessor()
        );
    }
}
