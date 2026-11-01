<?php

declare(strict_types = 1);

namespace Noldors\LighthouseIdempotency\Scalars;

use GraphQL\Error\Error;
use Illuminate\Support\Str;
use GraphQL\Language\AST\Node;
use GraphQL\Type\Definition\ScalarType;
use GraphQL\Language\AST\StringValueNode;

/**
 * UUID key owned by this package; independent from application identifier scalars.
 */
final class IdempotencyKey extends ScalarType
{
    public function serialize(mixed $value): string
    {
        return $this->coerce($value);
    }

    public function parseValue(mixed $value): string
    {
        return $this->coerce($value);
    }

    public function parseLiteral(Node $valueNode, array|null $variables = null): string
    {
        if (! $valueNode instanceof StringValueNode) {
            throw new Error((string) \__('lighthouse-idempotency::messages.invalid_key'), $valueNode);
        }

        return $this->coerce($valueNode->value);
    }

    private function coerce(mixed $value): string
    {
        if (! \is_string($value) || ! Str::isUuid($value)) {
            throw new Error((string) \__('lighthouse-idempotency::messages.invalid_key'));
        }

        return \mb_strtolower($value);
    }
}
