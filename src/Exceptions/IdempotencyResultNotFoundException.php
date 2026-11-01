<?php

declare(strict_types = 1);

namespace Noldors\LighthouseIdempotency\Exceptions;

use RuntimeException;

final class IdempotencyResultNotFoundException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'The recorded idempotency result is no longer available.',
        );
    }
}
