<?php

declare(strict_types = 1);

namespace Noldors\LighthouseIdempotency\Tests\Fixtures\Guards;

use Illuminate\Contracts\Auth\Authenticatable;
use Nuwave\Lighthouse\Exceptions\AuthenticationException;
use Nuwave\Lighthouse\Auth\GuardDirective as NativeGuardDirective;

final class GuardDirective extends NativeGuardDirective
{
    public static int $authenticationCount = 0;

    public static bool $allowed = true;

    /**
     * @param array<string|null> $guards
     */
    protected function authenticate(array $guards): Authenticatable
    {
        ++self::$authenticationCount;
        $actor = parent::authenticate($guards);

        if (! self::$allowed) {
            throw new AuthenticationException('Custom guard authentication revoked.', $guards);
        }

        return $actor;
    }
}
