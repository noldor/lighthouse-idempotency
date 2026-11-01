<?php

declare(strict_types = 1);

namespace Noldors\LighthouseIdempotency\Execution;

use Closure;
use WeakMap;
use LogicException;
use GraphQL\Type\Schema;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\FieldDefinition;
use Nuwave\Lighthouse\Events\StartExecution;
use GraphQL\Language\AST\FieldDefinitionNode;
use Nuwave\Lighthouse\Schema\DirectiveLocator;
use GraphQL\Language\AST\ObjectTypeDefinitionNode;
use Noldors\LighthouseIdempotency\Directives\IdempotencyDirective;

/**
 * @phpstan-import-type FieldResolver from \GraphQL\Executor\Executor as FieldResolverFn
 */
final class PrepareFieldResolvers
{
    /**
     * @var WeakMap<Schema, true>
     */
    private WeakMap $preparedSchemas;

    /**
     * @var WeakMap<FieldDefinition, true>
     */
    private WeakMap $preparedFields;

    public function __construct()
    {
        $this->preparedSchemas = new WeakMap();
        $this->preparedFields = new WeakMap();
    }

    public function handle(StartExecution $event): void
    {
        ValidateClientDirectiveArguments::handle($event);
        $schema = $event->schema;

        if (isset($this->preparedSchemas[$schema])) {
            return;
        }

        // Inspect the schema being executed, including fields prepared by another package.
        $directives = \resolve(DirectiveLocator::class);

        foreach ($schema->getTypeMap() as $type) {
            if (! $type instanceof ObjectType || ! $type->astNode instanceof ObjectTypeDefinitionNode) {
                continue;
            }

            foreach ($type->getFields() as $field) {
                if ($field->astNode instanceof FieldDefinitionNode) {
                    $this->prepare($field, $field->astNode, $directives);
                }
            }
        }

        $this->preparedSchemas[$schema] = true;
    }

    private function prepare(FieldDefinition $field, FieldDefinitionNode $node, DirectiveLocator $directives): void
    {
        if (isset($this->preparedFields[$field])) {
            return;
        }

        if (! \is_callable($field->resolveFn)) {
            throw new LogicException("Field {$field->name} must have a finished Lighthouse resolver.");
        }

        /** @var FieldResolverFn $resolver */
        $resolver = $field->resolveFn;
        $idempotency = $directives->exclusiveOfType($node, IdempotencyDirective::class);
        $field->resolveFn = $idempotency instanceof IdempotencyDirective
            ? $idempotency->wrapResolver($resolver)
            : $this->validateClientKey($resolver);
        $this->preparedFields[$field] = true;
    }

    /**
     * @param FieldResolverFn $resolver
     */
    private function validateClientKey(callable $resolver): Closure
    {
        return static function (mixed $root, array $args, mixed $context, ResolveInfo $info) use ($resolver): mixed {
            ClientIdempotencyKey::values($info);

            /** @var array<string, mixed> $args */
            return $resolver($root, $args, $context, $info);
        };
    }
}
