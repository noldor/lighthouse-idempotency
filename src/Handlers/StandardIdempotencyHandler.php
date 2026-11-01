<?php

declare(strict_types = 1);

namespace Noldors\LighthouseIdempotency\Handlers;

use Override;
use UnitEnum;
use BackedEnum;
use LogicException;
use DateTimeInterface;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Context;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Noldors\LighthouseIdempotency\Contracts\IdempotencyHandler;
use Noldors\LighthouseIdempotency\Services\IdempotencyDatabase;
use Noldors\LighthouseIdempotency\Exceptions\IdempotencyResultNotFoundException;

final readonly class StandardIdempotencyHandler implements IdempotencyHandler
{
    /**
     * @param class-string<Model>|null $modelClass
     * @param list<string> $exclude public argument paths, before Lighthouse transformations
     */
    public function __construct(
        private string|null $modelClass,
        private string $replayAbility,
        private string|null $scopeContext = null,
        private array $exclude = [],
        private IdempotencyDatabase $database = new IdempotencyDatabase(),
    ) {
        if (null !== $modelClass && $this->database->connection() !== new $modelClass()->getConnection()) {
            throw new LogicException('Standard idempotency models must use the configured database connection.');
        }
    }

    #[Override]
    public function scope(array $arguments, GraphQLContext $context): string
    {
        $actor = $this->actor($context);
        $id = $actor->getAuthIdentifier();

        if ((! \is_int($id) && ! \is_string($id)) || '' === (string) $id) {
            throw new LogicException('Idempotency requires a persistent actor identifier.');
        }

        $scope = [$actor::class, (string) $id];

        if (null !== $this->scopeContext) {
            $value = Context::has($this->scopeContext)
                ? Context::get($this->scopeContext)
                : Context::getHidden($this->scopeContext);

            if ((! \is_int($value) && ! \is_string($value)) || '' === (string) $value) {
                throw new LogicException('The configured idempotency scope context must contain a trusted string or integer.');
            }

            $scope[] = $this->scopeContext;
            $scope[] = (string) $value;
        }

        return \json_encode($scope, \JSON_THROW_ON_ERROR);
    }

    #[Override]
    public function input(array $arguments, GraphQLContext $context): array
    {
        Arr::forget($arguments, $this->exclude);

        return \array_map($this->normalizeInput(...), $arguments);
    }

    #[Override]
    public function store(mixed $result): array
    {
        if (null === $this->modelClass) {
            $this->assertJsonValue($result);

            return ['value' => $result];
        }

        if (! $result instanceof $this->modelClass
            || ! $result->exists
            || $result->getConnection() !== $this->database->connection()
        ) {
            throw new LogicException('The standard idempotency handler requires a persisted result of the configured model on the configured connection.');
        }

        $id = $result->getKey();

        if ((! \is_int($id) && ! \is_string($id)) || '' === (string) $id) {
            throw new LogicException('The idempotency result model requires a persistent identifier.');
        }

        return ['id' => $id];
    }

    #[Override]
    public function authorizeReplay(array $payload, array $arguments, GraphQLContext $context): void
    {
        $target = null === $this->modelClass ? [$arguments, $payload['value']] : $this->model($payload);
        Gate::forUser($this->actor($context))->authorize($this->replayAbility, $target);
    }

    #[Override]
    public function restore(array $payload, array $arguments, GraphQLContext $context): mixed
    {
        if (null === $this->modelClass) {
            return $payload['value'];
        }

        $model = $this->model($payload);
        Gate::forUser($this->actor($context))->authorize($this->replayAbility, $model);

        return $model;
    }

    private function actor(GraphQLContext $context): Authenticatable
    {
        return $context->user() ?? throw new AuthenticationException();
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private function model(array $payload): Model
    {
        $modelClass = $this->modelClass ?? throw new LogicException('No idempotency result model is configured.');
        $id = $payload['id'] ?? null;

        if (! \is_string($id) && ! \is_int($id)) {
            throw new LogicException('The stored idempotency result must contain a model identifier.');
        }

        return $modelClass::query()->useWritePdo()->find($id)
            ?? throw new IdempotencyResultNotFoundException();
    }

    private function normalizeInput(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->utc()->format('Y-m-d\\TH:i:s.u\\Z');
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if (\is_array($value)) {
            return \array_map($this->normalizeInput(...), $value);
        }

        $this->assertJsonValue($value);

        return $value;
    }

    private function assertJsonValue(mixed $value): void
    {
        if (\is_array($value)) {
            foreach ($value as $item) {
                $this->assertJsonValue($item);
            }

            return;
        }

        if (null !== $value && ! \is_scalar($value)) {
            throw new LogicException('The standard idempotency handler supports JSON values; use a custom handler for other runtime values.');
        }
    }
}
