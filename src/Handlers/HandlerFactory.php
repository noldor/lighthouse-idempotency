<?php

declare(strict_types = 1);

namespace Noldors\LighthouseIdempotency\Handlers;

use LogicException;
use Illuminate\Database\Eloquent\Model;
use Noldors\LighthouseIdempotency\Contracts\IdempotencyHandler;
use Noldors\LighthouseIdempotency\Services\IdempotencyDatabase;

final class HandlerFactory
{
    /**
     * @param class-string<IdempotencyHandler> $handlerClass
     * @param class-string<Model>|null $modelClass
     * @param list<string> $exclude
     */
    public function make(string $handlerClass, string|null $modelClass, string $replayAbility, string|null $scopeContext, array $exclude): IdempotencyHandler
    {
        if (StandardIdempotencyHandler::class === $handlerClass) {
            return new StandardIdempotencyHandler($modelClass, $replayAbility, $scopeContext, $exclude, \resolve(IdempotencyDatabase::class));
        }

        $handler = \resolve($handlerClass);

        if (! $handler instanceof IdempotencyHandler) {
            throw new LogicException('The configured handler binding must resolve an IdempotencyHandler.');
        }

        return $handler;
    }
}
