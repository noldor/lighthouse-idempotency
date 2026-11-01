<?php

declare(strict_types = 1);

namespace Noldors\LighthouseIdempotency\Execution;

use LogicException;
use GraphQL\Error\Error;
use GraphQL\Executor\Values;
use GraphQL\Type\Definition\ResolveInfo;

final class ClientIdempotencyKey
{
    /**
     * Evaluate supplied arguments even on fields that do not support idempotency.
     * GraphQL otherwise does not coerce executable custom directive arguments at execution time.
     *
     * @return list<string|null>
     */
    public static function values(ResolveInfo $info): array
    {
        $directive = $info->schema->getDirective('idempotent');
        $keys = [];

        foreach ($info->fieldNodes as $node) {
            $arguments = null === $directive
                ? null
                : Values::getDirectiveValues(
                    $directive,
                    $node,
                    $info->variableValues,
                    $info->schema,
                );

            if (null === $arguments) {
                $keys[] = null;

                continue;
            }

            $key = $arguments['key'];

            if (! \is_string($key)) {
                throw new LogicException('The idempotent directive requires the IdempotencyKey scalar.');
            }

            $keys[] = \mb_strtolower($key);
        }

        return $keys;
    }

    public static function resolve(ResolveInfo $info, bool $required): string|null
    {
        $keys = \array_unique(self::values($info), \SORT_REGULAR);

        if (\count($keys) > 1) {
            throw new Error((string) \__('lighthouse-idempotency::messages.key_conflict'), extensions: [
                'code' => 'IDEMPOTENCY_KEY_CONFLICT',
            ]);
        }

        $key = \array_shift($keys);

        if (null === $key && $required) {
            throw new Error((string) \__('lighthouse-idempotency::messages.key_required'), extensions: [
                'code' => 'IDEMPOTENCY_KEY_REQUIRED',
            ]);
        }

        return $key;
    }
}
