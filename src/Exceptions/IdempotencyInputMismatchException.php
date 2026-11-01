<?php

declare(strict_types = 1);

namespace Noldors\LighthouseIdempotency\Exceptions;

use RuntimeException;

final class IdempotencyInputMismatchException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'The idempotency key has already been used with different input.',
        );
    }
}
