<?php

declare(strict_types = 1);

namespace Noldors\LighthouseIdempotency\Tests\Fixtures\IdempotencyGraphQL;

use Override;
use LogicException;
use RuntimeException;
use GraphQL\Error\Error;
use Illuminate\Support\Facades\Gate;
use Illuminate\Auth\AuthenticationException;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Noldors\LighthouseIdempotency\Tests\Fixtures\Record;
use Noldors\LighthouseIdempotency\Contracts\IdempotencyHandler;

final class RecordHandler implements IdempotencyHandler
{
    public int $scopeCalls = 0;

    public bool $failStore = false;

    /**
     * @param array<string, mixed> $arguments
     */
    #[Override]
    public function scope(array $arguments, GraphQLContext $context): string
    {
        ++$this->scopeCalls;
        $actor = $context->user() ?? throw new AuthenticationException();
        $actorId = $actor->getAuthIdentifier();
        $input = $this->originalInput($arguments);
        $tenant = $input['tenant'] ?? null;

        if ((! \is_int($actorId) && ! \is_string($actorId)) || ! \is_string($tenant)) {
            throw new LogicException('The fixture requires the original tenant argument.');
        }

        return $actorId . ':' . $tenant;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function input(array $arguments, GraphQLContext $context): array
    {
        $input = $this->originalInput($arguments);
        unset($input['key'], $input['tenant'], $input['intent']);

        return $input;
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function store(mixed $result): array
    {
        if ($this->failStore) {
            throw new RuntimeException('The fixture payload cannot be stored.');
        }

        if (! $result instanceof Record) {
            throw new LogicException('The fixture requires a Record result.');
        }

        return ['id' => $result->id];
    }

    /**
     * @param array<array-key, mixed> $payload
     * @param array<string, mixed> $arguments
     */
    #[Override]
    public function authorizeReplay(array $payload, array $arguments, GraphQLContext $context): void
    {
        Gate::authorize('replay-idempotent-record', $payload['id']);
    }

    /**
     * @param array<array-key, mixed> $payload
     * @param array<string, mixed> $arguments
     */
    #[Override]
    public function restore(array $payload, array $arguments, GraphQLContext $context): Record
    {
        $id = $payload['id'] ?? null;

        if (! \is_int($id)) {
            throw new LogicException('The fixture requires a recorded integer identifier.');
        }

        return Record::query()->find($id) ?? throw new Error('The recorded fixture result is missing.');
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function originalInput(array $arguments): array
    {
        /** @var array<string, mixed>|null $input */
        $input = $arguments['input'] ?? null;

        if (! \is_array($input)) {
            throw new LogicException('The fixture requires original public input arguments.');
        }

        return $input;
    }
}
