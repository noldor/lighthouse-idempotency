<?php

declare(strict_types = 1);

namespace Noldors\LighthouseIdempotency\Contracts;

use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * An operation's replay contract. Arguments are original, coerced public GraphQL inputs.
 * Implementations must not retain invocation state across calls.
 */
interface IdempotencyHandler
{
    /**
     * Verify the required identity from the authenticated context and identify its stable actor/tenant scope.
     *
     * @param array<string, mixed> $arguments
     */
    public function scope(array $arguments, GraphQLContext $context): string;

    /**
     * Prepare significant JSON-compatible input without the key, preserving promised input distinctions.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<array-key, mixed>
     */
    public function input(array $arguments, GraphQLContext $context): array;

    /**
     * Encode a successful synchronous result as a minimal JSON-compatible payload, not a PHP object.
     *
     * @return array<array-key, mixed>
     */
    public function store(mixed $result): array;

    /**
     * Enforce applicable current authorization before returning or disclosing a retained outcome.
     *
     * @param array<array-key, mixed> $payload
     * @param array<string, mixed> $arguments
     */
    public function authorizeReplay(array $payload, array $arguments, GraphQLContext $context): void;

    /**
     * Restore the promised runtime result. Missing/deleted results must never start a fresh execution.
     *
     * @param array<array-key, mixed> $payload
     * @param array<string, mixed> $arguments
     */
    public function restore(array $payload, array $arguments, GraphQLContext $context): mixed;
}
