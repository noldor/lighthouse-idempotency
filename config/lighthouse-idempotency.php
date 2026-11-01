<?php

declare(strict_types = 1);

use Noldors\LighthouseIdempotency\Handlers\StandardIdempotencyHandler;

return [
    // The ledger and mandatory operation writes must share this PostgreSQL connection.
    'connection' => null,
    'table' => 'idempotency_records',
    'default_ttl' => 259_200,
    'default_handler' => StandardIdempotencyHandler::class,
    'default_required' => true,
    'prune_batch_size' => 1_000,
];
