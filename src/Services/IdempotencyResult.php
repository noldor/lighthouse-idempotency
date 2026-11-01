<?php

declare(strict_types = 1);

namespace Noldors\LighthouseIdempotency\Services;

final readonly class IdempotencyResult
{
    /**
     * @param array<array-key, mixed> $payload
     */
    public function __construct(
        public array $payload,
        public bool $replayed,
    ) {}
}
