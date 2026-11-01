<?php

declare(strict_types = 1);

return [
    'invalid_key' => 'IdempotencyKey must be a valid UUID string.',
    'key_required' => 'An idempotency key is required for this operation.',
    'key_conflict' => 'Merged fields must provide the same idempotency key.',
    'in_progress' => 'An operation with this key is in progress. Retry with the same key.',
    'input_mismatch' => 'The idempotency key has already been used with different input.',
    'result_not_found' => 'The recorded operation result is no longer available.',
    'invalid_batch' => 'The batch size must be an integer between 1 and 10000.',
    'pruned' => 'Deleted expired results: :count.',
    'migration_exists' => 'An idempotency migration for this table already exists.',
    'migration_created' => 'Created migration: :path',
];
