<?php

declare(strict_types = 1);

namespace Noldors\LighthouseIdempotency\Console;

use Illuminate\Console\Command;
use Noldors\LighthouseIdempotency\Services\PruneExpiredIdempotencyRecords;

final class PruneIdempotencyRecords extends Command
{
    protected $signature = 'idempotency:prune {--batch= : Maximum records per cleanup batch (1-10000); defaults to configuration}';

    protected $description = 'Delete expired completed idempotency outcomes';

    public function handle(PruneExpiredIdempotencyRecords $prune): int
    {
        $batch = \filter_var($this->option('batch') ?? \config('lighthouse-idempotency.prune_batch_size', 1_000), \FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 10_000],
        ]);

        if (false === $batch) {
            $this->error(
                (string) \__('lighthouse-idempotency::messages.invalid_batch'),
            );

            return self::FAILURE;
        }
        $this->info(
            (string) \__('lighthouse-idempotency::messages.pruned', ['count' => $prune->handle($batch)]),
        );

        return self::SUCCESS;
    }
}
