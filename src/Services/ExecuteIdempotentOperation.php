<?php

declare(strict_types = 1);

namespace Noldors\LighthouseIdempotency\Services;

use Closure;
use LogicException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Illuminate\Support\Carbon;
use Noldors\LighthouseIdempotency\Exceptions\IdempotencyInProgressException;
use Noldors\LighthouseIdempotency\Exceptions\IdempotencyInputMismatchException;

final class ExecuteIdempotentOperation
{
    public function __construct(
        private readonly IdempotencyLock $lock,
        private readonly IdempotencyDatabase $database,
    ) {}

    /**
     * All mandatory writes in $execute must use the configured PostgreSQL connection.
     * The caller establishes current identity and supplies operation-specific replay authorization.
     *
     * @param array<array-key, mixed> $input significant JSON-compatible input, without the key
     * @param Closure(): array<array-key, mixed> $execute
     * @param Closure(array<array-key, mixed>): void $authorizeReplay
     */
    public function handle(
        string $operation,
        string $scope,
        string $key,
        array $input,
        Closure $execute,
        Closure $authorizeReplay,
        int|null $ttl = null,
    ): IdempotencyResult {
        $ttl ??= \config('lighthouse-idempotency.default_ttl', 259_200);

        if (! \is_int($ttl) || 0 >= $ttl) {
            throw new InvalidArgumentException('Idempotency TTL must be a positive number of seconds.');
        }

        if ('' === $operation || \mb_strlen($operation) > 255 || '' === $scope || ! Str::isUuid($key)) {
            throw new InvalidArgumentException('Idempotency requires an operation (1–255 characters), a nonempty scope and a UUID key.');
        }

        $connection = $this->database->connection();
        $table = $this->database->table();
        $physicalTable = $connection->getQueryGrammar()->wrapTable($table);

        $scopeHash = \hash('sha256', $scope);
        $key = \mb_strtolower($key);
        $inputHash = \hash('sha256', $this->encode($input));
        $identity = ['scope_hash' => $scopeHash, 'operation' => $operation, 'key' => $key];

        return $connection->transaction(
            function () use ($connection, $table, $physicalTable, $identity, $inputHash, $execute, $authorizeReplay, $ttl): IdempotencyResult {
                // A transaction-owned try-lock avoids unbounded waiting and is released on commit/rollback.
                if (! $this->lock->acquire($connection, $identity, $physicalTable)) {
                    throw new IdempotencyInProgressException();
                }

                $records = $connection->table($table)->where($identity);

                /** @var object{result_payload: string|null, input_hash: string, completed_at: string|null, expires_at: string|null}|null $record */
                $record = $records->first();

                if (null !== $record
                    && null !== $record->result_payload
                    && null !== $record->completed_at
                    && null !== $record->expires_at
                    && Carbon::parse($record->expires_at)->lessThanOrEqualTo(Carbon::now())
                ) {
                    $records->delete();
                    $record = null;
                }

                if (null !== $record) {
                    // PostgreSQL advisory locks are reentrant: an unfinished own claim is still in progress.
                    if (null === $record->result_payload || null === $record->completed_at) {
                        throw new IdempotencyInProgressException();
                    }

                    $payload = $this->decode($record->result_payload);
                    $authorizeReplay($payload);

                    if (! \hash_equals($record->input_hash, $inputHash)) {
                        throw new IdempotencyInputMismatchException();
                    }

                    return new IdempotencyResult($payload, true);
                }

                $connection->table($table)->insert([
                    ...$identity,
                    'input_hash' => $inputHash,
                    'created_at' => Carbon::now()->format('Y-m-d H:i:s.uP'),
                ]);
                $payload = $execute();
                $encoded = $this->encode($payload);
                $completedAt = Carbon::now();
                $records->update([
                    'result_payload' => $encoded,
                    'completed_at' => $completedAt->format('Y-m-d H:i:s.uP'),
                    'expires_at' => $completedAt->copy()->addSeconds($ttl)->format('Y-m-d H:i:s.uP'),
                ]);

                return new IdempotencyResult($this->decode($encoded), false);
            },
        );
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private function encode(array $value): string
    {
        return \json_encode($this->canonicalize($value), \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (\is_array($value)) {
            $isList = \array_is_list($value);
            $items = \array_map($this->canonicalize(...), $value);

            if ($isList) {
                return $items;
            }

            \ksort($items, \SORT_STRING);

            return (object) $items;
        }

        if (null === $value || \is_scalar($value)) {
            return $value;
        }

        throw new InvalidArgumentException('Idempotency input and stored payload must contain JSON primitives and arrays only.');
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decode(string $payload): array
    {
        $decoded = \json_decode($payload, true, 512, \JSON_THROW_ON_ERROR);

        if (! \is_array($decoded)) {
            throw new LogicException('The stored idempotency payload must be an array.');
        }

        return $decoded;
    }
}
