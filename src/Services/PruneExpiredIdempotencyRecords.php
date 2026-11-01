<?php

declare(strict_types = 1);

namespace Noldors\LighthouseIdempotency\Services;

use InvalidArgumentException;
use Illuminate\Support\Carbon;

final class PruneExpiredIdempotencyRecords
{
    public function __construct(
        private readonly IdempotencyDatabase $database,
    ) {}

    public function handle(int|null $batch = null): int
    {
        $batch ??= \config('lighthouse-idempotency.prune_batch_size', 1_000);

        if (! \is_int($batch) || 1 > $batch || 10_000 < $batch) {
            throw new InvalidArgumentException('Idempotency cleanup batch size must be between 1 and 10000.');
        }

        $connection = $this->database->connection();
        $table = $connection->getQueryGrammar()->wrapTable($this->database->table());
        $cutoff = Carbon::now()->format('Y-m-d H:i:s.uP');
        $deleted = 0;

        do {
            // Select and delete the same physical rows atomically, without waiting on active writes.
            $count = $connection->delete(
                <<<SQL
                    WITH expired AS (
                        SELECT ctid
                        FROM {$table}
                        WHERE completed_at IS NOT NULL
                          AND result_payload IS NOT NULL
                          AND expires_at <= ?::timestamptz
                        ORDER BY expires_at
                        LIMIT ?
                        FOR UPDATE SKIP LOCKED
                    )
                    DELETE FROM {$table} AS records
                    USING expired
                    WHERE records.ctid = expired.ctid
                    SQL,
                [$cutoff, $batch],
            );
            $deleted += $count;
        } while ($count === $batch);

        return $deleted;
    }
}
