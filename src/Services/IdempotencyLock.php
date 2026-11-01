<?php

declare(strict_types = 1);

namespace Noldors\LighthouseIdempotency\Services;

use Illuminate\Database\Connection;

final class IdempotencyLock
{
    /**
     * @param array{scope_hash: string, operation: string, key: string} $identity
     */
    public function acquire(Connection $connection, array $identity, string $physicalTable): bool
    {
        \ksort($identity, \SORT_STRING);
        $encoded = \json_encode([$physicalTable, $identity], \JSON_THROW_ON_ERROR);

        /** @var object{acquired: int|string} $lock SELECT without FROM always returns one row. */
        $lock = $connection->selectOne(
            'SELECT CASE WHEN pg_try_advisory_xact_lock(hashtextextended(?, 0)) THEN 1 ELSE 0 END AS acquired',
            [$encoded],
        );

        return 1 === (int) $lock->acquired;
    }
}
