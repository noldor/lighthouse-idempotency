<?php

declare(strict_types = 1);

namespace Noldors\LighthouseIdempotency\Errors;

use RuntimeException;
use GraphQL\Error\ClientAware;
use GraphQL\Error\ProvidesExtensions;
use Noldors\LighthouseIdempotency\Exceptions\IdempotencyInProgressException;
use Noldors\LighthouseIdempotency\Exceptions\IdempotencyInputMismatchException;
use Noldors\LighthouseIdempotency\Exceptions\IdempotencyResultNotFoundException;

final class IdempotencyError extends RuntimeException implements ClientAware, ProvidesExtensions
{
    private readonly string $errorCode;

    public function __construct(IdempotencyInProgressException|IdempotencyInputMismatchException|IdempotencyResultNotFoundException $previous)
    {
        [$messageKey, $this->errorCode] = match ($previous::class) {
            IdempotencyInProgressException::class => ['in_progress', 'IDEMPOTENCY_IN_PROGRESS'],
            IdempotencyInputMismatchException::class => ['input_mismatch', 'IDEMPOTENCY_INPUT_MISMATCH'],
            IdempotencyResultNotFoundException::class => ['result_not_found', 'IDEMPOTENCY_RESULT_NOT_FOUND'],
        };
        parent::__construct((string) \__('lighthouse-idempotency::messages.' . $messageKey), previous: $previous);
    }

    public function isClientSafe(): bool
    {
        return true;
    }

    /**
     * @return array{code:string}
     */
    public function getExtensions(): array
    {
        return ['code' => $this->errorCode];
    }
}
