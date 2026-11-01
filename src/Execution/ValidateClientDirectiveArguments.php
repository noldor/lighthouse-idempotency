<?php

declare(strict_types = 1);

namespace Noldors\LighthouseIdempotency\Execution;

use Closure;
use GraphQL\Utils\AST;
use GraphQL\Executor\Values;
use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\AST\VariableNode;
use GraphQL\Language\AST\NullValueNode;
use GraphQL\Language\AST\NonNullTypeNode;
use GraphQL\Language\AST\SelectionSetNode;
use GraphQL\Language\AST\FragmentSpreadNode;
use GraphQL\Language\AST\InlineFragmentNode;
use Nuwave\Lighthouse\Events\StartExecution;
use GraphQL\Language\AST\FragmentDefinitionNode;

/**
 * Closes the native nullable-variable/default hole without changing shared field definitions.
 */
final class ValidateClientDirectiveArguments
{
    /**
     * @var array<string,true>
     */
    private array $visitedFragments = [];

    public static function handle(StartExecution $event): void
    {
        $directive = $event->schema->getDirective('idempotent');
        $operation = AST::getOperationAST($event->query, $event->operationName);

        if (null === $directive || null === $operation) {
            return;
        }
        $variables = $event->variables ?? [];
        $nullableDefaults = [];

        foreach ($operation->variableDefinitions as $definition) {
            if (! $definition->type instanceof NonNullTypeNode
                && null !== $definition->defaultValue
                && ! $definition->defaultValue instanceof NullValueNode
                && \array_key_exists($definition->variable->name->value, $variables)
                && null === $variables[$definition->variable->name->value]
            ) {
                $nullableDefaults[$definition->variable->name->value] = true;
            }
        }

        if ([] === $nullableDefaults) {
            return;
        }
        $fragments = [];

        foreach ($event->query->definitions as $definition) {
            if ($definition instanceof FragmentDefinitionNode) {
                $fragments[$definition->name->value] = $definition;
            }
        }
        $validator = new self();
        $validator->visit(
            $operation->selectionSet,
            static function (FieldNode $field) use ($event, $directive, $variables, $nullableDefaults): void {
                foreach ($field->directives as $node) {
                    if ('idempotent' !== $node->name->value) {
                        continue;
                    }

                    foreach ($node->arguments as $argument) {
                        if ('key' !== $argument->name->value || ! $argument->value instanceof VariableNode) {
                            continue;
                        }
                        $name = $argument->value->name->value;

                        if (isset($nullableDefaults[$name])
                            && \array_key_exists($name, $variables)
                            && null === $variables[$name]
                        ) {
                            Values::getDirectiveValues(
                                $directive,
                                $field,
                                $variables,
                                $event->schema,
                            );
                        }
                    }
                }
            },
            $fragments,
        );
    }

    /**
     * @param Closure(FieldNode):void $validate
     * @param array<string,FragmentDefinitionNode> $fragments
     */
    private function visit(SelectionSetNode $selectionSet, Closure $validate, array $fragments): void
    {
        foreach ($selectionSet->selections as $selection) {
            if ($selection instanceof FieldNode) {
                $validate($selection);

                if (null !== $selection->selectionSet) {
                    $this->visit(
                        $selection->selectionSet,
                        $validate,
                        $fragments,
                    );
                }
            } elseif ($selection instanceof InlineFragmentNode) {
                $this->visit(
                    $selection->selectionSet,
                    $validate,
                    $fragments,
                );
            } elseif ($selection instanceof FragmentSpreadNode) {
                $name = $selection->name->value;

                if (! isset($this->visitedFragments[$name]) && isset($fragments[$name])) {
                    $this->visitedFragments[$name] = true;
                    $this->visit(
                        $fragments[$name]->selectionSet,
                        $validate,
                        $fragments,
                    );
                }
            }
        }
    }
}
