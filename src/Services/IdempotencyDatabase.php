<?php

declare(strict_types = 1);

namespace Noldors\LighthouseIdempotency\Services;

use LogicException;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Connection;

/**
 * Resolves storage from the current container; no request or connection is retained.
 */
final class IdempotencyDatabase
{
    public function connection(): Connection
    {
        $name = \config('lighthouse-idempotency.connection');

        if (null !== $name && (! \is_string($name) || '' === $name)) {
            throw new LogicException('The idempotency connection must be a connection name or null.');
        }
        $connection = DB::connection($name);

        if ($connection->getDriverName() !== 'pgsql') {
            throw new LogicException('Idempotency requires a PostgreSQL connection.');
        }

        return $connection;
    }

    public function table(): string
    {
        $table = \config('lighthouse-idempotency.table', 'idempotency_records');

        if (! \is_string($table) || \mb_trim($table) === '' || \str_contains($table, "\0")) {
            throw new LogicException('The idempotency table must be a nonempty table name.');
        }

        return $table;
    }
}
