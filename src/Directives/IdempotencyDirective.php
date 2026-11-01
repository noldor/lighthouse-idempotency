<?php

declare(strict_types = 1);

namespace Noldors\LighthouseIdempotency\Directives;

use Closure;
use Override;
use LogicException;
use ReflectionClass;
use GraphQL\Deferred;
use GraphQL\Executor\Executor;
use GraphQL\Executor\Promise\Promise;
use GraphQL\Type\Definition\ResolveInfo;
use Nuwave\Lighthouse\Auth\GuardDirective;
use GraphQL\Language\AST\FieldDefinitionNode;
use Nuwave\Lighthouse\Schema\AST\DocumentAST;
use Nuwave\Lighthouse\Schema\DirectiveLocator;
use GraphQL\Language\AST\ObjectTypeDefinitionNode;
use GraphQL\Language\AST\InterfaceTypeDefinitionNode;
use Nuwave\Lighthouse\Exceptions\DefinitionException;
use Nuwave\Lighthouse\Schema\Directives\BaseDirective;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Nuwave\Lighthouse\Support\Contracts\FieldManipulator;
use Noldors\LighthouseIdempotency\Errors\IdempotencyError;
use Noldors\LighthouseIdempotency\Handlers\HandlerFactory;
use Noldors\LighthouseIdempotency\Contracts\IdempotencyHandler;
use Noldors\LighthouseIdempotency\Services\IdempotencyDatabase;
use Noldors\LighthouseIdempotency\Execution\ClientIdempotencyKey;
use Noldors\LighthouseIdempotency\Handlers\StandardIdempotencyHandler;
use Noldors\LighthouseIdempotency\Services\ExecuteIdempotentOperation;
use Noldors\LighthouseIdempotency\Exceptions\IdempotencyInProgressException;
use Noldors\LighthouseIdempotency\Exceptions\IdempotencyInputMismatchException;
use Noldors\LighthouseIdempotency\Exceptions\IdempotencyResultNotFoundException;

/**
 * @phpstan-import-type FieldResolver from \GraphQL\Executor\Executor as FieldResolverFn
 */
final class IdempotencyDirective extends BaseDirective implements FieldManipulator
{
    public static function definition(): string
    {
        return /** @lang GraphQL */ <<<'GRAPHQL'
            """
            Enable synchronous mutation replay through the client @idempotent directive.
            """
            directive @idempotency(
                "Stable operation name; defaults to the SDL coordinate, for example Mutation.create."
                operation: String
                "Require a client key; omitted or null uses package configuration."
                required: Boolean
                "Positive retention seconds; omitted or null uses package configuration."
                ttl: Int
                "Concrete IdempotencyHandler class; omitted or null uses package configuration."
                handler: String
                "Standard result model; without a model the standard handler stores a JSON value."
                model: String
                "Required standard-handler Gate ability for current replay access."
                replayAbility: String
                "Trusted visible or hidden Laravel Context key added to the actor scope."
                scopeContext: String
                "Public dot-separated argument paths excluded from the input fingerprint."
                exclude: [String!]! = []
            ) on FIELD_DEFINITION
            GRAPHQL;
    }

    #[Override]
    public function manipulateFieldDefinition(
        DocumentAST &$documentAST,
        FieldDefinitionNode &$fieldDefinition,
        InterfaceTypeDefinitionNode|ObjectTypeDefinitionNode &$parentType,
    ): void {
        $location = $parentType->name->value . '.' . $fieldDefinition->name->value;

        $this->validateActionField($location, $parentType->name->value, $fieldDefinition);
        $this->validateOperationOptions($location);
        $handler = $this->handlerClass();

        if (StandardIdempotencyHandler::class !== $handler) {
            $this->validateCustomHandlerOptions($location);

            return;
        }

        $this->validateReplayAccess($location);
        $this->validateResultModel($location);
        $this->validateExcludedPaths($location);
    }

    /**
     * @param FieldResolverFn $resolver
     */
    public function wrapResolver(callable $resolver): Closure
    {
        /** @var string|null $operation */
        $operation = $this->directiveArgValue('operation');

        /** @var bool $required */
        $required = $this->directiveArgValue('required') ?? \config('lighthouse-idempotency.default_required', true);

        /** @var int $ttl */
        $ttl = $this->directiveArgValue('ttl') ?? \config('lighthouse-idempotency.default_ttl', 259_200);

        /** @var class-string<IdempotencyHandler> $handlerClass */
        $handlerClass = $this->handlerClass();
        $modelClass = $this->directiveHasArgument('model') ? $this->getModelClass() : null;

        /** @var string $ability */
        $ability = $this->directiveArgValue('replayAbility', '');

        /** @var string|null $scopeContext */
        $scopeContext = $this->directiveArgValue('scopeContext');

        /** @var list<string> $exclude */
        $exclude = $this->directiveArgValue('exclude', []);

        return static function (mixed $root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo) use (
            $resolver,
            $operation,
            $required,
            $ttl,
            $handlerClass,
            $modelClass,
            $ability,
            $scopeContext,
            $exclude,
        ): mixed {
            if ('mutation' !== $resolveInfo->operation->operation) {
                throw new LogicException('@idempotency requires a mutation operation.');
            }

            $key = ClientIdempotencyKey::resolve($resolveInfo, $required);

            /** @var array<string, mixed> $args */
            $execute = static function () use ($resolver, $root, $args, $context, $resolveInfo): mixed {
                $result = $resolver($root, $args, $context, $resolveInfo);

                if ($result instanceof Deferred
                    || $result instanceof Promise
                    || Executor::getDefaultPromiseAdapter()->isThenable($result)
                ) {
                    throw new LogicException('@idempotency requires a synchronous resolver.');
                }

                return $result;
            };

            if (null === $key) {
                return \resolve(IdempotencyDatabase::class)->connection()->transaction($execute);
            }

            // Resolve invocation dependencies now, rather than retaining actors in an Octane schema.
            /** @var IdempotencyHandler $handler */
            $handler = \resolve(HandlerFactory::class)->make(
                $handlerClass,
                $modelClass,
                $ability,
                $scopeContext,
                $exclude,
            );

            $scope = $handler->scope($args, $context);
            $input = $handler->input($args, $context);
            $freshResult = null;

            try {
                $result = \resolve(ExecuteIdempotentOperation::class)->handle(
                    $operation ?? $resolveInfo->parentType->name . '.' . $resolveInfo->fieldName,
                    $scope,
                    $key,
                    $input,
                    static function () use ($execute, $handler, &$freshResult): array {
                        $freshResult = $execute();

                        return $handler->store($freshResult);
                    },
                    static function (array $payload) use ($handler, $args, $context): void {
                        $handler->authorizeReplay($payload, $args, $context);
                    },
                    $ttl,
                );

                return $result->replayed ? $handler->restore($result->payload, $args, $context) : $freshResult;
            } catch (IdempotencyInProgressException|IdempotencyInputMismatchException|IdempotencyResultNotFoundException $exception) {
                throw new IdempotencyError($exception);
            }
        };
    }

    /**
     * @return class-string<IdempotencyHandler>
     */
    private function handlerClass(): string
    {
        $handler = $this->directiveArgValue('handler')
            ?? \config('lighthouse-idempotency.default_handler', StandardIdempotencyHandler::class);

        if (! \is_string($handler)
            || ! \is_a($handler, IdempotencyHandler::class, true)
            || ! new ReflectionClass($handler)->isInstantiable()
        ) {
            throw new DefinitionException('@idempotency requires a concrete IdempotencyHandler class.');
        }

        return $handler;
    }

    private function validateActionField(string $location, string $parentName, FieldDefinitionNode $fieldDefinition): void
    {
        if (\in_array($parentName, ['Query', 'Subscription'], true)) {
            throw new DefinitionException("@idempotency on {$location} requires a mutation action field.");
        }

        $directives = \resolve(DirectiveLocator::class);

        foreach ($fieldDefinition->directives as $directive) {
            $name = $directive->name->value;

            if ('namespaced' === $name) {
                throw new DefinitionException("@idempotency on {$location} cannot wrap a namespace container.");
            }

            if ('guard' === $name || \is_a($directives->resolve($name), GuardDirective::class, true)) {
                throw new DefinitionException("@idempotency on {$location} cannot be combined with @guard on the same field. Authenticate before entering this field.");
            }
        }
    }

    private function validateOperationOptions(string $location): void
    {
        $operation = $this->directiveArgValue('operation');
        $required = $this->directiveArgValue('required') ?? \config('lighthouse-idempotency.default_required', true);
        $ttl = $this->directiveArgValue('ttl') ?? \config('lighthouse-idempotency.default_ttl', 259_200);

        if (($this->directiveHasArgument('operation') && (! \is_string($operation) || '' === \mb_trim($operation) || \mb_strlen($operation) > 255))
            || ! \is_bool($required)
            || ! \is_int($ttl)
            || 0 >= $ttl
        ) {
            throw new DefinitionException("@idempotency on {$location} requires a nonempty operation up to 255 characters and a positive TTL in seconds.");
        }
    }

    private function validateCustomHandlerOptions(string $location): void
    {
        foreach (['model', 'replayAbility', 'scopeContext', 'exclude'] as $option) {
            if ($this->directiveHasArgument($option)) {
                throw new DefinitionException("@idempotency on {$location} cannot combine a custom handler with {$option}.");
            }
        }
    }

    private function validateReplayAccess(string $location): void
    {
        $ability = $this->directiveArgValue('replayAbility');
        $scopeContext = $this->directiveArgValue('scopeContext');

        if (! \is_string($ability)
            || '' === \mb_trim($ability)
            || ($this->directiveHasArgument('scopeContext') && (! \is_string($scopeContext) || '' === \mb_trim($scopeContext)))
        ) {
            throw new DefinitionException("@idempotency on {$location} requires a replayAbility and a nonempty scopeContext when configured.");
        }
    }

    private function validateResultModel(string $location): void
    {
        if ($this->directiveHasArgument('model')) {
            $modelClass = $this->getModelClass();
            $model = new $modelClass();
            $connection = \resolve(IdempotencyDatabase::class)->connection();

            if ($connection->getName() !== ($model->getConnectionName() ?? \config('database.default'))
                || $model->getConnection() !== $connection
            ) {
                throw new DefinitionException("@idempotency on {$location} requires a model on the configured database connection.");
            }
        }
    }

    private function validateExcludedPaths(string $location): void
    {
        /** @var list<string> $excluded */
        $excluded = $this->directiveArgValue('exclude', []);

        foreach ($excluded as $path) {
            if (1 !== \preg_match('/^[_A-Za-z][_0-9A-Za-z]*(?:\\.[_A-Za-z][_0-9A-Za-z]*)*$/D', $path)) {
                throw new DefinitionException("@idempotency on {$location} requires public dot-separated argument paths in exclude.");
            }
        }
    }
}
