<?php

declare(strict_types = 1);

namespace Noldors\LighthouseIdempotency\Tests\Integration\GraphQL;

use stdClass;
use Nuwave\Lighthouse\Schema\Validator;
use Nuwave\Lighthouse\Auth\GuardDirective;
use PHPUnit\Framework\Attributes\TestWith;
use Nuwave\Lighthouse\Schema\DirectiveLocator;
use Nuwave\Lighthouse\Exceptions\DefinitionException;
use Noldors\LighthouseIdempotency\Tests\Support\GraphqlTestCase;

final class IdempotentDirectiveDefinitionTest extends GraphqlTestCase
{
    private const string SCHEMA = <<<'GRAPHQL'
        type Query { noop: Boolean @mock }
        type Mutation {
            create(input: CreateInput! @spread): String @mock
                @idempotency(handler: "Noldors\\LighthouseIdempotency\\Tests\\Fixtures\\IdempotencyGraphQL\\RecordHandler")
        }
        input CreateInput { tenant: String! @drop, title: String! }
        GRAPHQL;

    public function testOperationDefaultsToTheSchemaCoordinateWithoutAnInputKeyField(): void
    {
        $this->useSchema(self::SCHEMA);

        $this->expectNotToPerformAssertions();
        $this->application->make(Validator::class)->validate();
    }

    public function testAnExplicitOperationCannotBeEmpty(): void
    {
        $this->assertInvalidSchema(
            \str_replace('@idempotency(', '@idempotency(operation: "", ', self::SCHEMA),
        );
    }

    #[TestWith([stdClass::class])]
    #[TestWith(['MissingIdempotencyHandler'])]
    public function testHandlerMustBeAnExistingImplementation(string $handler): void
    {
        $this->assertInvalidSchema(
            \str_replace(
                'Noldors\\\\LighthouseIdempotency\\\\Tests\\\\Fixtures\\\\IdempotencyGraphQL\\\\RecordHandler',
                $handler,
                self::SCHEMA,
            ),
        );
    }

    #[TestWith(['model: "Noldors\\\\LighthouseIdempotency\\\\Tests\\\\Fixtures\\\\Record"'])]
    #[TestWith(['replayAbility: "view"'])]
    #[TestWith(['scopeContext: "tenant"'])]
    #[TestWith(['exclude: []'])]
    public function testCustomHandlerRejectsEveryExplicitStandardHandlerOption(string $option): void
    {
        $this->assertInvalidSchema(
            \str_replace('@idempotency(', '@idempotency(' . $option . ', ', self::SCHEMA),
        );
    }

    public function testStandardHandlerRequiresAnExplicitReplayAbility(): void
    {
        $this->assertInvalidSchema(
            \str_replace(
                'handler: "Noldors\\\\LighthouseIdempotency\\\\Tests\\\\Fixtures\\\\IdempotencyGraphQL\\\\RecordHandler"',
                'model: "Noldors\\\\LighthouseIdempotency\\\\Tests\\\\Fixtures\\\\Record"',
                self::SCHEMA,
            ),
        );
    }

    #[TestWith(['0'])]
    #[TestWith(['-1'])]
    public function testTtlMustBePositive(string $ttl): void
    {
        $this->assertInvalidSchema(
            \str_replace('@idempotency(', '@idempotency(ttl: ' . $ttl . ', ', self::SCHEMA),
        );
    }

    #[TestWith([stdClass::class])]
    #[TestWith(['MissingIdempotencyModel'])]
    #[TestWith(['Noldors\\\\LighthouseIdempotency\\\\Tests\\\\Fixtures\\\\IdempotencyGraphQL\\\\OtherConnectionRecord'])]
    public function testStandardModelMustUseTheDefaultEloquentConnection(string $model): void
    {
        $this->assertInvalidSchema(
            \str_replace(
                'handler: "Noldors\\\\LighthouseIdempotency\\\\Tests\\\\Fixtures\\\\IdempotencyGraphQL\\\\RecordHandler"',
                'model: "' . $model . '", replayAbility: "view"',
                self::SCHEMA,
            ),
        );
    }

    #[TestWith(['Query'])]
    #[TestWith(['Subscription'])]
    public function testDirectiveCannotBePlacedOnNonMutationRoots(string $rootType): void
    {
        $schema = \str_replace('type Mutation', 'type ' . $rootType, self::SCHEMA);

        if ('Query' === $rootType) {
            $schema = \str_replace('type Query { noop: Boolean @mock }', '', $schema);
        }

        $this->assertInvalidSchema($schema);
    }

    public function testNamespaceContainerCannotOwnTheServerDirective(): void
    {
        $this->assertInvalidSchema(
            <<<'GRAPHQL'
                type Query { noop: Boolean @mock }
                type Mutation {
                    records: RecordMutations! @namespaced
                        @idempotency(handler: "Noldors\\LighthouseIdempotency\\Tests\\Fixtures\\IdempotencyGraphQL\\RecordHandler")
                }
                type RecordMutations { create: String @mock }
                GRAPHQL,
        );
    }

    #[TestWith(['type Mutation { execute: String @guard @mock @idempotency(replayAbility: "view") }'])]
    #[TestWith(['type Mutation { execute: String @mock @idempotency(replayAbility: "view") @guard }'])]
    #[TestWith(['type Mutation @guard { execute: String @mock @idempotency(replayAbility: "view") }'])]
    #[TestWith([
        'type Mutation { noop: Boolean @mock } extend type Mutation @guard { execute: String @mock @idempotency(replayAbility: "view") }',
    ])]
    #[TestWith(['type Mutation { execute: String @guard @mock @idempotency(required: false, replayAbility: "view") }'])]
    public function testGuardCannotShareAnIdempotentActionField(string $mutation): void
    {
        $this->useSchema(
            'type Query { noop: Boolean @mock } ' . $mutation,
        );
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage(
            '@idempotency on Mutation.execute cannot be combined with @guard on the same field. Authenticate before entering this field.',
        );
        $this->application->make(Validator::class)->validate();
    }

    public function testCustomHandlerCannotBypassTheFieldGuardRestriction(): void
    {
        $this->useSchema(
            \str_replace('@idempotency(', '@guard @idempotency(', self::SCHEMA),
        );
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage(
            '@idempotency on Mutation.create cannot be combined with @guard',
        );
        $this->application->make(Validator::class)->validate();
    }

    public function testRenamedNativeGuardCannotBypassTheFieldRestriction(): void
    {
        $this->useSchema(
            'directive @authenticated(with: [String!]) repeatable on FIELD_DEFINITION | OBJECT '
            . 'type Query { noop: Boolean @mock } '
            . 'type Mutation { execute: String @authenticated @mock @idempotency(replayAbility: "view") }',
        );
        $this->application->make(DirectiveLocator::class)->setResolved('authenticated', GuardDirective::class);
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage(
            '@idempotency on Mutation.execute cannot be combined with @guard',
        );
        $this->application->make(Validator::class)->validate();
    }

    private function assertInvalidSchema(string $schema): void
    {
        $this->useSchema($schema);
        $this->expectException(DefinitionException::class);
        $this->application->make(Validator::class)->validate();
    }
}
