<?php

declare(strict_types = 1);

namespace Noldors\LighthouseIdempotency\Tests\Integration\GraphQL;

use Override;
use LogicException;
use RuntimeException;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Exceptions;
use PHPUnit\Framework\Attributes\TestWith;
use Noldors\LighthouseIdempotency\Tests\Fixtures\Record;
use Noldors\LighthouseIdempotency\Tests\Support\GraphqlTestCase;
use Noldors\LighthouseIdempotency\Tests\Fixtures\IdempotencyGraphQL\OtherActor;

final class StandardIdempotencyHandlerTest extends GraphqlTestCase
{
    private const string KEY = 'c99b2872-a358-452b-b1f7-761d2c46e9be';

    private const string SCHEMA = <<<'GRAPHQL'
        type Query { noop: Boolean @mock }
        type Mutation {
            create(input: CreateInput! @spread): Record! @create(model: "Record")
                @canModel(ability: "create-idempotent-record", model: "Record")
                @idempotency(model: "Noldors\\LighthouseIdempotency\\Tests\\Fixtures\\Record", replayAbility: "replay-idempotent-record")
        }
        type Record { id: ID!, title: String @canRoot(ability: "view-idempotent-record") }
        input CreateInput { title: String! }
        GRAPHQL;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(new User()->forceFill(['id' => 11]));
        Gate::define('create-idempotent-record', static fn (): bool => true);
        Gate::define('replay-idempotent-record', static fn (): bool => true);
        Gate::define('view-idempotent-record', static fn (): bool => true);
    }

    public function testStandardModelHandlerReplaysNativeCreateWithCurrentModelAndSelection(): void
    {
        $this->useSchema(self::SCHEMA);
        $first = $this->graphQL($this->document(), ['key' => self::KEY, 'input' => ['title' => 'original']])
            ->assertGraphQLErrorFree();
        $id = $first->json('data.create.id');
        Record::query()->whereKey($id)->update(['title' => 'edited later']);

        $this->graphQL($this->document(), ['key' => self::KEY, 'input' => ['title' => 'original']])
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.create.id', $id)
            ->assertJsonPath('data.create.title', 'edited later');

        $this->assertDatabaseCount('fixture_records', 1);
    }

    public function testClientDirectiveIsIgnoredWithoutServerAnnotationAndCreatesNoLedgerEntry(): void
    {
        $this->useSchema(
            \str_replace(
                '@idempotency(model: "Noldors\\\\LighthouseIdempotency\\\\Tests\\\\Fixtures\\\\Record", replayAbility: "replay-idempotent-record")',
                '',
                self::SCHEMA,
            ),
        );
        $variables = ['key' => self::KEY, 'input' => ['title' => 'independent writes']];
        $first = $this->graphQL($this->document(), $variables)->assertGraphQLErrorFree();
        $second = $this->graphQL($this->document(), $variables)->assertGraphQLErrorFree();

        $this->assertNotSame($first->json('data.create.id'), $second->json('data.create.id'));
        $this->assertDatabaseCount('fixture_records', 2);
        $this->assertDatabaseCount('idempotency_records', 0);
    }

    public function testOptionalServerDirectiveWithoutClientKeyRunsFreshPipelineWithoutLedger(): void
    {
        $this->useSchema(
            \str_replace('@idempotency(', '@idempotency(required: false, ', self::SCHEMA),
        );
        $document = 'mutation ($input: CreateInput!) { create(input: $input) { id title } }';
        $first = $this->graphQL($document, ['input' => ['title' => 'unkeyed']])->assertGraphQLErrorFree();
        $second = $this->graphQL($document, ['input' => ['title' => 'unkeyed']])->assertGraphQLErrorFree();

        $this->assertNotSame($first->json('data.create.id'), $second->json('data.create.id'));
        $this->assertDatabaseCount('fixture_records', 2);
        $this->assertDatabaseCount('idempotency_records', 0);
    }

    public function testMissingRequiredClientDirectiveRejectsBeforeBusinessWrites(): void
    {
        $this->useSchema(self::SCHEMA);

        $this->graphQL(
            'mutation { create(input: {title: "requires intent"}) { id } }',
        )->assertJsonPath('errors.0.extensions.code', 'IDEMPOTENCY_KEY_REQUIRED');

        $this->assertDatabaseCount('fixture_records', 0);
        $this->assertDatabaseCount('idempotency_records', 0);
    }

    #[TestWith(['not-a-uuid'])]
    #[TestWith([null])]
    public function testIgnoredClientDirectiveStillHasStrictNativeUuidValidation(string|null $key): void
    {
        $this->useSchema(
            \str_replace(
                '@idempotency(model: "Noldors\\\\LighthouseIdempotency\\\\Tests\\\\Fixtures\\\\Record", replayAbility: "replay-idempotent-record")',
                '',
                self::SCHEMA,
            ),
        );

        $response = $this->graphQL($this->document(), ['key' => $key, 'input' => ['title' => 'invalid intent']]);

        $message = $response->json('errors.0.message');
        $this->assertIsString($message);
        $this->assertStringContainsString('IdempotencyKey', $message);
        $this->assertDatabaseCount('fixture_records', 0);
        $this->assertDatabaseCount('idempotency_records', 0);
    }

    public function testOptionalUnkeyedExecutionRetainsNativeValidationAndAuthorization(): void
    {
        $schema = \str_replace('@idempotency(', '@idempotency(required: false, ', self::SCHEMA);
        $this->useSchema(
            \str_replace('title: String! }', 'title: String! @rules(apply: ["min:3"]) }', $schema),
        );
        Gate::define('create-idempotent-record', static fn (): bool => false);
        $document = 'mutation ($input: CreateInput!) { create(input: $input) { id } }';

        $this->graphQL($document, ['input' => ['title' => 'x']])->assertGraphQLValidationKeys(['input.title']);
        $this->graphQL($document, ['input' => ['title' => 'authorized title']])
            ->assertGraphQLErrorMessage('This action is unauthorized.');

        $this->assertDatabaseCount('fixture_records', 0);
        $this->assertDatabaseCount('idempotency_records', 0);
    }

    public function testStandardReplayAuthorizesTheCurrentModelBeforeInputComparison(): void
    {
        $this->useSchema(self::SCHEMA);
        $variables = ['key' => self::KEY, 'input' => ['title' => 'original']];
        $response = $this->graphQL($this->document(), $variables)->assertGraphQLErrorFree();
        $id = $response->json('data.create.id');
        Record::query()->whereKey($id)->update(['title' => 'revoked']);
        $authorized = 0;
        Gate::define('replay-idempotent-record', function (User $actor, Record $record) use (&$authorized): bool {
            ++$authorized;
            $this->assertSame(11, $actor->getAuthIdentifier());
            $this->assertSame('revoked', $record->getAttribute('title'));

            return false;
        });

        $this->graphQL($this->document(), ['key' => self::KEY, 'input' => ['title' => 'changed input']])
            ->assertGraphQLErrorMessage('This action is unauthorized.');

        $this->assertSame(1, $authorized);
        $this->assertDatabaseCount('fixture_records', 1);
        $this->assertDatabaseHas('fixture_records', ['id' => $id, 'title' => 'revoked']);
    }

    public function testRestoredModelIsAuthorizedAgainWhenItsStateChangesAfterTheFirstCheck(): void
    {
        $this->useSchema(self::SCHEMA);
        $variables = ['key' => self::KEY, 'input' => ['title' => 'original']];
        $response = $this->graphQL($this->document(), $variables)->assertGraphQLErrorFree();
        $id = $response->json('data.create.id');
        $checks = 0;
        Gate::define(
            'replay-idempotent-record',
            static function (User $actor, Record $record) use (&$checks, $id): bool {
                ++$checks;
                $allowed = 'original' === $record->getAttribute('title');

                if (1 === $checks) {
                    // Simulate a committed access-relevant change between authorization and restoration.
                    Record::query()->whereKey($id)->update(['title' => 'revoked']);
                }

                return $allowed;
            },
        );

        $this->graphQL($this->document(), $variables)
            ->assertGraphQLErrorMessage('This action is unauthorized.')
            ->assertJsonPath('data', null);

        $this->assertSame(2, $checks);
        $this->assertDatabaseCount('fixture_records', 1);
        $this->assertDatabaseHas('fixture_records', ['id' => $id, 'title' => 'revoked']);
        $this->assertDatabaseCount('idempotency_records', 1);
    }

    public function testStandardReplayRetainsOutputFieldPolicies(): void
    {
        $this->useSchema(self::SCHEMA);
        $variables = ['key' => self::KEY, 'input' => ['title' => 'private title']];
        $response = $this->graphQL($this->document(), $variables)->assertGraphQLErrorFree();
        Gate::define('view-idempotent-record', static fn (): bool => false);

        $this->graphQL($this->document(), $variables)
            ->assertGraphQLErrorMessage('This action is unauthorized.')
            ->assertJsonPath('data.create.id', $response->json('data.create.id'))
            ->assertJsonPath('data.create.title', null)
            ->assertJsonPath('errors.0.path', ['create', 'title']);

        $this->assertDatabaseCount('fixture_records', 1);
    }

    public function testMissingStandardModelNeverReexecutesTheMutation(): void
    {
        $this->useSchema(self::SCHEMA);
        $variables = ['key' => self::KEY, 'input' => ['title' => 'deleted result']];
        $response = $this->graphQL($this->document(), $variables)->assertGraphQLErrorFree();
        Record::query()->whereKey($response->json('data.create.id'))->delete();

        foreach ([1, 2] as $attempt) {
            $this->graphQL($this->document(), $variables)
                ->assertGraphQLErrorMessage(
                    'The recorded operation result is no longer available.',
                )->assertJsonPath('errors.0.extensions.code', 'IDEMPOTENCY_RESULT_NOT_FOUND')
                ->assertJsonPath('errors.0.path', ['create'])
                ->assertJsonPath('data', null);
        }

        $this->assertDatabaseCount('fixture_records', 0);
        $this->assertDatabaseCount('idempotency_records', 1);
    }

    public function testModelDisappearingAfterReplayAuthorizationNeverReexecutesTheMutation(): void
    {
        $this->useSchema(self::SCHEMA);
        $variables = ['key' => self::KEY, 'input' => ['title' => 'deleted during replay']];
        $response = $this->graphQL($this->document(), $variables)->assertGraphQLErrorFree();
        $id = $response->json('data.create.id');
        $checks = 0;
        Gate::define('replay-idempotent-record', static function (User $actor, Record $record) use (&$checks): bool {
            ++$checks;
            $record->delete();

            return true;
        });

        foreach ([1, 2] as $attempt) {
            $this->graphQL($this->document(), $variables)
                ->assertGraphQLErrorMessage(
                    'The recorded operation result is no longer available.',
                )->assertJsonPath('errors.0.extensions.code', 'IDEMPOTENCY_RESULT_NOT_FOUND')
                ->assertJsonPath('errors.0.path', ['create'])
                ->assertJsonPath('data', null);
        }

        $this->assertSame(1, $checks);
        $this->assertDatabaseMissing('fixture_records', ['id' => $id]);
        $this->assertDatabaseCount('fixture_records', 0);
        $this->assertDatabaseCount('idempotency_records', 1);
    }

    public function testStandardHandlerDoesNotAllowGuestScopeFallback(): void
    {
        $this->useSchema(self::SCHEMA);
        Auth::logout();

        $this->graphQL($this->document(), ['key' => self::KEY, 'input' => ['title' => 'guest write']])
            ->assertGraphQLErrorMessage('Unauthenticated.');

        $this->assertDatabaseCount('fixture_records', 0);
        $this->assertDatabaseCount('idempotency_records', 0);
    }

    public function testDefaultScopeSeparatesActorClassAndIdentifier(): void
    {
        $this->useSchema(self::SCHEMA);
        $first = $this->graphQL($this->document(), ['key' => self::KEY, 'input' => ['title' => 'first actor']])
            ->assertGraphQLErrorFree();
        $this->actingAs(
            new OtherActor()->forceFill(['id' => 11]),
        );
        $second = $this->graphQL($this->document(), ['key' => self::KEY, 'input' => ['title' => 'other actor class']])
            ->assertGraphQLErrorFree();
        $this->actingAs(new User()->forceFill(['id' => 22]));
        $third = $this->graphQL($this->document(), ['key' => self::KEY, 'input' => ['title' => 'other actor id']])
            ->assertGraphQLErrorFree();

        $this->assertNotSame($first->json('data.create.id'), $second->json('data.create.id'));
        $this->assertNotSame($second->json('data.create.id'), $third->json('data.create.id'));
        $this->assertDatabaseCount('fixture_records', 3);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testConfiguredTrustedContextSeparatesScopes(bool $hidden): void
    {
        $this->useSchema(
            \str_replace('@idempotency(', '@idempotency(scopeContext: "fixture.tenant", ', self::SCHEMA),
        );
        $hidden ? Context::addHidden('fixture.tenant', 'tenant-a') : Context::add('fixture.tenant', 'tenant-a');
        $first = $this->graphQL($this->document(), ['key' => self::KEY, 'input' => ['title' => 'tenant a']])
            ->assertGraphQLErrorFree();
        $hidden ? Context::addHidden('fixture.tenant', 'tenant-b') : Context::add('fixture.tenant', 'tenant-b');
        $second = $this->graphQL($this->document(), ['key' => self::KEY, 'input' => ['title' => 'tenant b']])
            ->assertGraphQLErrorFree();

        $this->assertNotSame($first->json('data.create.id'), $second->json('data.create.id'));
        $this->assertDatabaseCount('fixture_records', 2);
    }

    public function testMissingConfiguredTrustedContextFailsClosed(): void
    {
        Exceptions::fake([LogicException::class]);
        $this->useSchema(
            \str_replace('@idempotency(', '@idempotency(scopeContext: "fixture.missing", ', self::SCHEMA),
        );

        $this->graphQL($this->document(), ['key' => self::KEY, 'input' => ['title' => 'unscoped write']])
            ->assertGraphQLErrorMessage('Internal server error');

        Exceptions::assertReported(LogicException::class);
        Exceptions::assertReportedCount(1);
        $this->assertDatabaseCount('fixture_records', 0);
        $this->assertDatabaseCount('idempotency_records', 0);
    }

    #[TestWith(['String', 'saved scalar'])]
    #[TestWith(['[String!]!', ['first', 'second']])]
    public function testWithoutModelStandardHandlerReplaysStoredScalarOrArray(string $type, mixed $value): void
    {
        $this->useSchema(
            <<<GRAPHQL
                type Query { noop: Boolean @mock }
                type Mutation { value(title: String!): {$type} @mock @idempotency(replayAbility: "replay-value") }
                GRAPHQL,
        );
        $executed = 0;
        $this->mockResolver(
            static function () use (&$executed, $value): mixed {
                ++$executed;

                return $value;
            },
        );
        Gate::define('replay-value', function (User $actor, array $arguments, mixed $stored) use ($value): bool {
            $this->assertSame(11, $actor->getAuthIdentifier());
            $this->assertSame(['title' => 'significant'], $arguments);
            $this->assertSame($value, $stored);

            return true;
        });
        $document = 'mutation { value(title: "significant") @idempotent(key: "c99b2872-a358-452b-b1f7-761d2c46e9be") }';
        $this->graphQL($document)->assertGraphQLErrorFree()->assertJsonPath('data.value', $value);
        $this->graphQL($document)->assertGraphQLErrorFree()->assertJsonPath('data.value', $value);

        $this->assertSame(1, $executed);
    }

    public function testDefaultCoordinateAndExplicitStableOperationUseAliasIndependentIdentities(): void
    {
        $schema = \str_replace(
            'type Record {',
            'extend type Mutation { renamed(input: CreateInput! @spread): Record! @create(model: "Record") @idempotency(operation: "Mutation.create", model: "Noldors\\\\LighthouseIdempotency\\\\Tests\\\\Fixtures\\\\Record", replayAbility: "replay-idempotent-record") } type Record {',
            self::SCHEMA,
        );
        $this->useSchema($schema);
        $variables = ['key' => self::KEY, 'input' => ['title' => 'stable identity']];
        $first = $this->graphQL($this->document(), $variables)->assertGraphQLErrorFree();
        $this->graphQL(\str_replace('create(input:', 'alias: renamed(input:', $this->document()), $variables)
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.alias.id', $first->json('data.create.id'));

        $this->assertDatabaseCount('fixture_records', 1);
        $this->assertDatabaseHas('idempotency_records', ['operation' => 'Mutation.create']);
    }

    public function testOnlyConfiguredPublicDotpathsAreExcludedBeforeNativeSpreadAndRename(): void
    {
        $this->useSchema(
            <<<'GRAPHQL'
                type Query { noop: Boolean @mock }
                type Mutation {
                    create(input: CreateInput! @spread): Record! @create(model: "Record")
                        @idempotency(model: "Noldors\\LighthouseIdempotency\\Tests\\Fixtures\\Record", replayAbility: "replay-idempotent-record", exclude: ["input.metadata.trace"])
                }
                type Record { id: ID!, title: String }
                input CreateInput {
                    publicTitle: String! @rename(attribute: "title")
                    metadata: MetadataInput! @drop
                }
                input MetadataInput { trace: String!, significant: String! }
                GRAPHQL,
        );
        $input = ['publicTitle' => 'original', 'metadata' => ['trace' => 'first attempt', 'significant' => 'keep']];
        $first = $this->graphQL($this->document(), ['key' => self::KEY, 'input' => $input])->assertGraphQLErrorFree();
        $input['metadata']['trace'] = 'retry attempt';

        $this->graphQL($this->document(), ['key' => self::KEY, 'input' => $input])
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.create.id', $first->json('data.create.id'));
        $input['metadata']['significant'] = 'changed';
        $this->graphQL($this->document(), ['key' => self::KEY, 'input' => $input])
            ->assertJsonPath('errors.0.extensions.code', 'IDEMPOTENCY_INPUT_MISMATCH');

        $this->assertDatabaseCount('fixture_records', 1);
        $this->assertDatabaseHas('fixture_records', ['title' => 'original']);
    }

    public function testOptionalUnkeyedFailureRollsBackTheFreshPipelineWithoutCreatingALedgerEntry(): void
    {
        Exceptions::fake([RuntimeException::class]);
        $schema = \str_replace('@idempotency(', '@idempotency(required: false, ', self::SCHEMA);
        $this->useSchema(
            \str_replace('@create(model: "Record")', '@mock', $schema),
        );
        $outerLevel = DB::transactionLevel();
        $this->mockResolver(function () use ($outerLevel): never {
            $this->assertGreaterThan($outerLevel, DB::transactionLevel());
            Record::query()->create(['title' => 'must roll back']);

            throw new RuntimeException('Unkeyed fixture write failed.');
        });

        $this->graphQL(
            'mutation { create(input: {title: "must roll back"}) { id } }',
        )->assertGraphQLErrorMessage('Internal server error');

        Exceptions::assertReported(
            static fn (RuntimeException $exception): bool => 'Unkeyed fixture write failed.' === $exception->getMessage(),
        );
        Exceptions::assertReportedCount(1);
        $this->assertSame($outerLevel, DB::transactionLevel());
        $this->assertDatabaseCount('fixture_records', 0);
        $this->assertDatabaseCount('idempotency_records', 0);
    }

    public function testStandardReplayDoesNotDefaultToAllowWhenTheAbilityIsUndefined(): void
    {
        $this->useSchema(
            \str_replace('replayAbility: "replay-idempotent-record"', 'replayAbility: "undefined-fixture-ability"', self::SCHEMA),
        );
        $variables = ['key' => self::KEY, 'input' => ['title' => 'requires explicit replay access']];
        $this->graphQL($this->document(), $variables)->assertGraphQLErrorFree();

        $this->graphQL($this->document(), $variables)->assertGraphQLErrorMessage('This action is unauthorized.');

        $this->assertDatabaseCount('fixture_records', 1);
    }

    public function testClientAnnotationOnAnUnannotatedQueryIsSemanticallyIgnored(): void
    {
        $this->useSchema(
            <<<'GRAPHQL'
                type Query { value: String @mock }
                GRAPHQL,
        );
        $executed = 0;
        $this->mockResolver(
            static function () use (&$executed): string {
                ++$executed;

                return 'query value';
            },
        );
        $document = 'query { value @idempotent(key: "c99b2872-a358-452b-b1f7-761d2c46e9be") }';

        $this->graphQL($document)->assertGraphQLErrorFree()->assertJsonPath('data.value', 'query value');
        $this->graphQL($document)->assertGraphQLErrorFree()->assertJsonPath('data.value', 'query value');

        $this->assertSame(2, $executed);
        $this->assertDatabaseCount('idempotency_records', 0);
    }

    public function testIgnoredClientDirectiveRejectsExplicitNullForANullableVariableWithAValidDefault(): void
    {
        $this->useSchema(
            <<<'GRAPHQL'
                type Query { value: String @mock }
                GRAPHQL,
        );
        $this->mockResolverExpects($this->never());
        $document = 'query ($k: IdempotencyKey = "c99b2872-a358-452b-b1f7-761d2c46e9be") { value @idempotent(key: $k) }';

        $this->graphQL($document, ['k' => null])
            ->assertGraphQLErrorMessage(
                'Argument "key" of non-null type "IdempotencyKey!" must not be null.',
            );

        $this->assertDatabaseCount('idempotency_records', 0);
    }

    private function document(): string
    {
        return 'mutation ($key: IdempotencyKey!, $input: CreateInput!) { create(input: $input) @idempotent(key: $key) { id title } }';
    }
}
