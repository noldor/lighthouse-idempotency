<?php

declare(strict_types = 1);

namespace Noldors\LighthouseIdempotency\Exceptions;

use RuntimeException;

final class IdempotencyInProgressException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'An operation with this idempotency key is already in progress.',
        );
    }
}
