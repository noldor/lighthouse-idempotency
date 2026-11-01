<?php

declare(strict_types = 1);

namespace Noldors\LighthouseIdempotency\Tests\Integration\GraphQL;

use Override;
use LogicException;
use GraphQL\Deferred;
use RuntimeException;
use GraphQL\Executor\Executor;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Nuwave\Lighthouse\Schema\Validator;
use Illuminate\Support\Facades\Exceptions;
use PHPUnit\Framework\Attributes\TestWith;
use Illuminate\Database\Events\QueryExecuted;
use Noldors\LighthouseIdempotency\Tests\Fixtures\Record;
use Noldors\LighthouseIdempotency\Tests\Support\GraphqlTestCase;
use Noldors\LighthouseIdempotency\Tests\Fixtures\IdempotencyGraphQL\RecordHandler;

final class IdempotentDirectiveTest extends GraphqlTestCase
{
    private const string KEY = 'c99b2872-a358-452b-b1f7-761d2c46e9be';

    private RecordHandler $handler;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->handler = new RecordHandler();
        $this->application->instance(RecordHandler::class, $this->handler);
        $this->actingAs(new User()->forceFill(['id' => 11]));
        Gate::define('create-idempotent-record', static fn (): bool => true);
        Gate::define('replay-idempotent-record', static fn (): bool => true);
        Gate::define('view-idempotent-record', static fn (): bool => true);
    }

    public function testNativeCreateReplaysBeforeFreshValidationWithoutRepeatingLifecycle(): void
    {
        $this->useCreateSchema();
        $created = 0;
        $validations = 0;
        Record::created(
            static function () use (&$created): void {
                ++$created;
            },
        );
        DB::listen(
            static function (QueryExecuted $query) use (&$validations): void {
                if (\str_contains($query->sql, 'count(*)') && \str_contains($query->sql, 'fixture_records')) {
                    ++$validations;
                }
            },
        );
        $input = ['tenant' => 'primary', 'title' => 'created once'];

        $response = $this->graphQL(
            'mutation ($key: IdempotencyKey! = "c99b2872-a358-452b-b1f7-761d2c46e9be", $input: CreateInput!) { create(input: $input) @idempotent(key: $key) { id title } }',
            ['input' => $input],
        )->assertGraphQLErrorFree()->assertJsonPath('data.create.title', 'created once');
        $id = $response->json('data.create.id');

        $this->graphQL(
            'mutation ($key: IdempotencyKey! = "c99b2872-a358-452b-b1f7-761d2c46e9be", $input: CreateInput!) { create(input: $input) @idempotent(key: $key) { id title } }',
            ['input' => $input],
        )->assertGraphQLErrorFree()->assertJsonPath('data.create.id', $id);

        $this->assertSame(1, $validations);
        $this->assertDatabaseCount('fixture_records', 1);
        $this->assertDatabaseHas('fixture_records', ['id' => $id, 'title' => 'created once']);
        $this->assertSame(1, $created);
        $this->assertSame(2, $this->handler->scopeCalls);
    }

    public function testChangedInputIsRejectedWithoutAnotherNativeCreate(): void
    {
        $this->useCreateSchema();
        $input = ['tenant' => 'primary', 'title' => 'original'];
        $this->graphQL(
            'mutation ($key: IdempotencyKey! = "c99b2872-a358-452b-b1f7-761d2c46e9be", $input: CreateInput!) { create(input: $input) @idempotent(key: $key) { id } }',
            [
                'input' => $input,
            ],
        )->assertGraphQLErrorFree();

        $this->graphQL(
            'mutation ($key: IdempotencyKey! = "c99b2872-a358-452b-b1f7-761d2c46e9be", $input: CreateInput!) { create(input: $input) @idempotent(key: $key) { id } }',
            [
                'input' => [...$input, 'title' => 'changed'],
            ],
        )->assertJsonPath(
            'errors.0.extensions.code',
            'IDEMPOTENCY_INPUT_MISMATCH',
        )->assertJsonPath(
            'errors.0.path',
            ['create'],
        );

        $this->assertDatabaseCount('fixture_records', 1);
        $this->assertDatabaseHas('fixture_records', ['title' => 'original']);
    }

    public function testReplayUsesTheCurrentResultAndTheNewSelectionAndAlias(): void
    {
        $this->useCreateSchema();
        $input = ['tenant' => 'primary', 'title' => 'original'];
        $response = $this->graphQL(
            'mutation ($key: IdempotencyKey! = "c99b2872-a358-452b-b1f7-761d2c46e9be", $input: CreateInput!) { first: create(input: $input) @idempotent(key: $key) { id } }',
            ['input' => $input],
        )->assertGraphQLErrorFree();
        $id = $response->json('data.first.id');
        Record::query()->whereKey($id)->update(['title' => 'edited later']);

        $this->graphQL(
            'mutation ($key: IdempotencyKey! = "c99b2872-a358-452b-b1f7-761d2c46e9be", $input: CreateInput!) { retried: create(input: $input) @idempotent(key: $key) { title id } }',
            ['input' => $input],
        )->assertGraphQLErrorFree()->assertJsonPath('data.retried.id', $id)
            ->assertJsonPath('data.retried.title', 'edited later');

        $this->assertDatabaseCount('fixture_records', 1);
    }

    public function testTheSameKeyIsIsolatedByCurrentActorAndTenant(): void
    {
        $this->useCreateSchema();
        $firstInput = ['tenant' => 'primary', 'title' => 'first actor'];
        $first = $this->graphQL(
            'mutation ($key: IdempotencyKey! = "c99b2872-a358-452b-b1f7-761d2c46e9be", $input: CreateInput!) { create(input: $input) @idempotent(key: $key) { id } }',
            ['input' => $firstInput],
        )->assertGraphQLErrorFree();
        $this->actingAs(new User()->forceFill(['id' => 22]));
        $second = $this->graphQL(
            'mutation ($key: IdempotencyKey! = "c99b2872-a358-452b-b1f7-761d2c46e9be", $input: CreateInput!) { create(input: $input) @idempotent(key: $key) { id } }',
            [
                'input' => [...$firstInput, 'title' => 'second actor'],
            ],
        )->assertGraphQLErrorFree();
        $third = $this->graphQL(
            'mutation ($key: IdempotencyKey! = "c99b2872-a358-452b-b1f7-761d2c46e9be", $input: CreateInput!) { create(input: $input) @idempotent(key: $key) { id } }',
            [
                'input' => [...$firstInput, 'tenant' => 'another', 'title' => 'another tenant'],
            ],
        )->assertGraphQLErrorFree();
        $this->actingAs(new User()->forceFill(['id' => 11]));

        $this->graphQL(
            'mutation ($key: IdempotencyKey! = "c99b2872-a358-452b-b1f7-761d2c46e9be", $input: CreateInput!) { create(input: $input) @idempotent(key: $key) { id } }',
            [
                'input' => $firstInput,
            ],
        )->assertGraphQLErrorFree()->assertJsonPath(
            'data.create.id',
            $first->json('data.create.id'),
        );

        $this->assertNotSame($first->json('data.create.id'), $second->json('data.create.id'));
        $this->assertNotSame($second->json('data.create.id'), $third->json('data.create.id'));
        $this->assertDatabaseCount('fixture_records', 3);
        $this->assertSame(4, $this->handler->scopeCalls);
    }

    public function testRevokedReplayAuthorizationWinsOverChangedInput(): void
    {
        $this->useCreateSchema();
        $input = ['tenant' => 'primary', 'title' => 'original'];
        $this->graphQL(
            'mutation ($key: IdempotencyKey! = "c99b2872-a358-452b-b1f7-761d2c46e9be", $input: CreateInput!) { create(input: $input) @idempotent(key: $key) { id } }',
            [
                'input' => $input,
            ],
        )->assertGraphQLErrorFree();
        Gate::define('replay-idempotent-record', static fn (): bool => false);

        $this->graphQL(
            'mutation ($key: IdempotencyKey! = "c99b2872-a358-452b-b1f7-761d2c46e9be", $input: CreateInput!) { create(input: $input) @idempotent(key: $key) { id } }',
            [
                'input' => [...$input, 'title' => 'changed'],
            ],
        )->assertGraphQLErrorMessage('This action is unauthorized.')->assertJsonPath(
            'data',
            null,
        );

        $this->assertDatabaseCount('fixture_records', 1);
        $this->assertDatabaseHas('fixture_records', ['title' => 'original']);
        Gate::define('replay-idempotent-record', static fn (): bool => true);
        $this->graphQL(
            'mutation ($key: IdempotencyKey! = "c99b2872-a358-452b-b1f7-761d2c46e9be", $input: CreateInput!) { create(input: $input) @idempotent(key: $key) { id } }',
            [
                'input' => $input,
            ],
        )->assertGraphQLErrorFree();
        $this->assertDatabaseCount('fixture_records', 1);
    }

    public function testFailedFreshValidationPrecedesNativeAuthorizationAndReleasesTheIntent(): void
    {
        $this->useCreateSchema();
        Record::query()->create(['title' => 'already taken']);
        $authorizations = 0;
        Gate::define('create-idempotent-record', static function () use (&$authorizations): bool {
            ++$authorizations;

            return false;
        });
        $input = ['tenant' => 'primary', 'title' => 'already taken'];
        $level = DB::transactionLevel();

        $this->graphQL(
            'mutation ($key: IdempotencyKey! = "c99b2872-a358-452b-b1f7-761d2c46e9be", $input: CreateInput!) { create(input: $input) @idempotent(key: $key) { id } }',
            [
                'input' => $input,
            ],
        )->assertGraphQLValidationKeys(['input.title']);

        $this->assertSame(0, $authorizations);
        $this->assertSame($level, DB::transactionLevel());
        $this->assertDatabaseCount('fixture_records', 1);
        $input['title'] = 'available';
        $this->graphQL(
            'mutation ($key: IdempotencyKey! = "c99b2872-a358-452b-b1f7-761d2c46e9be", $input: CreateInput!) { create(input: $input) @idempotent(key: $key) { id } }',
            [
                'input' => $input,
            ],
        )->assertGraphQLErrorMessage('This action is unauthorized.');

        $this->assertSame(1, $authorizations);
        $this->assertSame($level, DB::transactionLevel());
        $this->assertDatabaseCount('fixture_records', 1);
        Gate::define('create-idempotent-record', static fn (): bool => true);
        $this->graphQL(
            'mutation ($key: IdempotencyKey! = "c99b2872-a358-452b-b1f7-761d2c46e9be", $input: CreateInput!) { create(input: $input) @idempotent(key: $key) { title } }',
            [
                'input' => $input,
            ],
        )->assertGraphQLErrorFree()->assertJsonPath(
            'data.create.title',
            'available',
        );
        $this->assertDatabaseCount('fixture_records', 2);
    }

    public function testReplayPreservesNativeNestedOutputAuthorization(): void
    {
        $this->useCreateSchema();
        $input = ['tenant' => 'primary', 'title' => 'private title'];
        $response = $this->graphQL(
            'mutation ($key: IdempotencyKey! = "c99b2872-a358-452b-b1f7-761d2c46e9be", $input: CreateInput!) { create(input: $input) @idempotent(key: $key) { id title } }',
            ['input' => $input],
        )->assertGraphQLErrorFree();
        Gate::define('view-idempotent-record', static fn (): bool => false);

        $this->graphQL(
            'mutation ($key: IdempotencyKey! = "c99b2872-a358-452b-b1f7-761d2c46e9be", $input: CreateInput!) { retried: create(input: $input) @idempotent(key: $key) { id title } }',
            ['input' => $input],
        )->assertGraphQLErrorMessage('This action is unauthorized.')
            ->assertJsonPath('errors.0.path', ['retried', 'title'])
            ->assertJsonPath('data.retried.id', $response->json('data.create.id'))
            ->assertJsonPath('data.retried.title', null);

        $this->assertDatabaseCount('fixture_records', 1);
    }

    public function testMissingRecordedResultNeverReexecutesTheCreate(): void
    {
        $this->useCreateSchema();
        $input = ['tenant' => 'primary', 'title' => 'must remain deleted'];
        $response = $this->graphQL(
            'mutation ($key: IdempotencyKey! = "c99b2872-a358-452b-b1f7-761d2c46e9be", $input: CreateInput!) { create(input: $input) @idempotent(key: $key) { id } }',
            ['input' => $input],
        )->assertGraphQLErrorFree();
        Record::query()->whereKey($response->json('data.create.id'))->delete();

        for ($attempt = 0; 2 > $attempt; ++$attempt) {
            $this->graphQL(
                'mutation ($key: IdempotencyKey! = "c99b2872-a358-452b-b1f7-761d2c46e9be", $input: CreateInput!) { create(input: $input) @idempotent(key: $key) { id } }',
                [
                    'input' => $input,
                ],
            )->assertGraphQLErrorMessage(
                'The recorded fixture result is missing.',
            );
            $this->assertDatabaseCount('fixture_records', 0);
        }
    }

    public function testHandlersAreResolvedForEachInvocationAfterSchemaConstruction(): void
    {
        $this->useCreateSchema();
        $resolved = 0;
        $this->application->forgetInstance(RecordHandler::class);
        $this->application->bind(RecordHandler::class, static function () use (&$resolved): RecordHandler {
            ++$resolved;

            return new RecordHandler();
        });

        $this->application->make(Validator::class)->validate();
        $this->assertSame(0, $resolved);
        $input = ['tenant' => 'primary', 'title' => 'resolved at invocation'];
        $this->graphQL(
            'mutation ($key: IdempotencyKey! = "c99b2872-a358-452b-b1f7-761d2c46e9be", $input: CreateInput!) { create(input: $input) @idempotent(key: $key) { id } }',
            [
                'input' => $input,
            ],
        )->assertGraphQLErrorFree();
        $this->assertSame(1, $resolved);
        $this->graphQL(
            'mutation ($key: IdempotencyKey! = "c99b2872-a358-452b-b1f7-761d2c46e9be", $input: CreateInput!) { create(input: $input) @idempotent(key: $key) { id } }',
            [
                'input' => $input,
            ],
        )->assertGraphQLErrorFree();
        $this->assertSame(2, $resolved);
    }

    public function testAliasesCanCarryIndependentIntentKeysInOneRequest(): void
    {
        $this->useCreateSchema();
        $firstInput = ['tenant' => 'primary', 'title' => 'first intent'];
        $secondInput = [
            'tenant' => 'primary',
            'title' => 'second intent',
        ];

        $response = $this->graphQL(
            <<<'GRAPHQL'
                mutation ($first: CreateInput!, $second: CreateInput!) {
                    first: create(input: $first) @idempotent(key: "c99b2872-a358-452b-b1f7-761d2c46e9be") { id }
                    second: create(input: $second) @idempotent(key: "7cef22fb-cee7-480d-b2ad-840301d46639") { id }
                }
                GRAPHQL,
            ['first' => $firstInput, 'second' => $secondInput],
        )->assertGraphQLErrorFree();

        $this->assertNotSame($response->json('data.first.id'), $response->json('data.second.id'));
        $this->graphQL(
            'mutation ($key: IdempotencyKey! = "c99b2872-a358-452b-b1f7-761d2c46e9be", $input: CreateInput!) { retried: create(input: $input) @idempotent(key: $key) { id } }',
            [
                'input' => $secondInput,
                'key' => '7cef22fb-cee7-480d-b2ad-840301d46639',
            ],
        )->assertGraphQLErrorFree()->assertJsonPath(
            'data.retried.id',
            $response->json('data.second.id'),
        );
        $this->assertDatabaseCount('fixture_records', 2);
    }

    public function testADifferentKeyStillRunsNativeFreshValidation(): void
    {
        $this->useCreateSchema();
        $input = ['tenant' => 'primary', 'title' => 'one unique title'];
        $this->graphQL(
            'mutation ($key: IdempotencyKey! = "c99b2872-a358-452b-b1f7-761d2c46e9be", $input: CreateInput!) { create(input: $input) @idempotent(key: $key) { id } }',
            [
                'input' => $input,
            ],
        )->assertGraphQLErrorFree();

        $this->graphQL(
            'mutation ($key: IdempotencyKey! = "c99b2872-a358-452b-b1f7-761d2c46e9be", $input: CreateInput!) { create(input: $input) @idempotent(key: $key) { id } }',
            [
                'input' => $input,
                'key' => '7cef22fb-cee7-480d-b2ad-840301d46639',
            ],
        )->assertGraphQLValidationKeys(['input.title']);

        $this->assertDatabaseCount('fixture_records', 1);
    }

    #[TestWith(['not-a-uuid'])]
    #[TestWith([null])]
    public function testInvalidRequiredUuidIsRejectedBeforeTheHandler(string|null $key): void
    {
        $this->useCreateSchema();

        $response = $this->graphQL(
            'mutation ($key: IdempotencyKey! = "c99b2872-a358-452b-b1f7-761d2c46e9be", $input: CreateInput!) { create(input: $input) @idempotent(key: $key) { id } }',
            [
                'input' => ['tenant' => 'primary', 'title' => 'invalid key'],
                'key' => $key,
            ],
        );

        $message = $response->json('errors.0.message');
        $this->assertIsString($message);
        $this->assertStringContainsString('IdempotencyKey', $message);
        $this->assertSame(0, $this->handler->scopeCalls);
        $this->assertDatabaseCount('fixture_records', 0);
    }

    public function testOriginalPublicInputSurvivesNativeRenamingAndSpreading(): void
    {
        $this->useSchema(
            <<<'GRAPHQL'
                type Query { noop: Boolean @mock }
                type Mutation {
                    create(input: CreateInput! @spread): Record! @create(model: "Record")
                        @idempotency(operation: "fixture.record.create", handler: "Noldors\\LighthouseIdempotency\\Tests\\Fixtures\\IdempotencyGraphQL\\RecordHandler")
                }
                type Record { id: ID!, title: String }
                input CreateInput {
                    tenant: String! @drop
                    publicTitle: String! @rename(attribute: "title") @rules(apply: ["unique:fixture_records,title"])
                }
                GRAPHQL,
        );
        $input = ['tenant' => 'primary', 'publicTitle' => 'public name'];
        $response = $this->graphQL(
            'mutation ($key: IdempotencyKey! = "c99b2872-a358-452b-b1f7-761d2c46e9be", $input: CreateInput!) { create(input: $input) @idempotent(key: $key) { id title } }',
            ['input' => $input],
        )->assertGraphQLErrorFree()->assertJsonPath('data.create.title', 'public name');

        $this->graphQL(
            'mutation ($key: IdempotencyKey! = "c99b2872-a358-452b-b1f7-761d2c46e9be", $input: CreateInput!) { create(input: $input) @idempotent(key: $key) { id } }',
            [
                'input' => $input,
            ],
        )->assertGraphQLErrorFree()->assertJsonPath(
            'data.create.id',
            $response->json('data.create.id'),
        );
        $this->assertDatabaseCount('fixture_records', 1);
    }

    public function testExecutableLiteralKeyReplaysWithoutAnyKeyInputField(): void
    {
        $this->useCreateSchema();
        $document = 'mutation { create(input: {tenant: "primary", title: "literal key"}) @idempotent(key: "c99b2872-a358-452b-b1f7-761d2c46e9be") { id } }';
        $first = $this->graphQL($document)->assertGraphQLErrorFree();

        $this->graphQL($document)
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.create.id', $first->json('data.create.id'));

        $this->assertDatabaseCount('fixture_records', 1);
    }

    public function testNamespacedMutationWorksButTheSameFieldCannotExecuteAsAQuery(): void
    {
        Exceptions::fake([LogicException::class]);
        $this->useSchema(
            <<<'GRAPHQL'
                type Query { records: RecordMutations! @namespaced }
                type Mutation { records: RecordMutations! @namespaced }
                type RecordMutations {
                    create(input: CreateInput! @spread): Record! @create(model: "Record")
                        @idempotency(operation: "fixture.record.create", handler: "Noldors\\LighthouseIdempotency\\Tests\\Fixtures\\IdempotencyGraphQL\\RecordHandler")
                }
                type Record { id: ID!, title: String }
                input CreateInput { tenant: String! @drop, title: String! }
                GRAPHQL,
        );
        $input = ['tenant' => 'primary', 'title' => 'namespaced'];
        $this->graphQL(
            'mutation ($key: IdempotencyKey! = "c99b2872-a358-452b-b1f7-761d2c46e9be", $input: CreateInput!) { records { create(input: $input) @idempotent(key: $key) { id } } }',
            [
                'input' => $input,
            ],
        )->assertGraphQLErrorFree();

        $this->graphQL(
            'query ($key: IdempotencyKey! = "c99b2872-a358-452b-b1f7-761d2c46e9be", $input: CreateInput!) { records { create(input: $input) @idempotent(key: $key) { id } } }',
            [
                'input' => $input,
            ],
        )->assertJsonPath(
            'errors.0.path',
            ['records', 'create'],
        )->assertJsonPath(
            'data',
            null,
        )->assertGraphQLErrorMessage('Internal server error');

        Exceptions::assertReported(
            static fn (LogicException $exception): bool => '@idempotency requires a mutation operation.' === $exception->getMessage(),
        );
        Exceptions::assertReportedCount(1);
        $this->assertSame(1, $this->handler->scopeCalls);
        $this->assertDatabaseCount('fixture_records', 1);
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function testAsynchronousResolverResultsRollBackBusinessWritesAndTheClaim(bool $deferred): void
    {
        Exceptions::fake([LogicException::class]);
        $this->useSchema(
            <<<'GRAPHQL'
                type Query { noop: Boolean @mock }
                type Mutation {
                    create(input: CreateInput! @spread): Record! @mock
                        @idempotency(operation: "fixture.record.create", handler: "Noldors\\LighthouseIdempotency\\Tests\\Fixtures\\IdempotencyGraphQL\\RecordHandler")
                }
                type Record { id: ID!, title: String }
                input CreateInput { tenant: String! @drop, title: String! }
                GRAPHQL,
        );
        $this->mockResolver(
            static function (mixed $root, array $arguments) use ($deferred): mixed {
                $record = Record::query()->create(['title' => $arguments['title']]);

                return $deferred
                    ? new Deferred(static fn (): Record => $record)
                    : Executor::getDefaultPromiseAdapter()->createFulfilled($record);
            },
        );
        $input = ['tenant' => 'primary', 'title' => 'rolled back asynchronous write'];
        $level = DB::transactionLevel();

        $response = $this->graphQL(
            'mutation ($key: IdempotencyKey! = "c99b2872-a358-452b-b1f7-761d2c46e9be", $input: CreateInput!) { create(input: $input) @idempotent(key: $key) { id } }',
            [
                'input' => $input,
            ],
        );

        $response->assertGraphQLErrorMessage('Internal server error')->assertJsonPath('errors.0.path', ['create']);
        Exceptions::assertReported(
            static fn (LogicException $exception): bool => '@idempotency requires a synchronous resolver.' === $exception->getMessage(),
        );
        Exceptions::assertReportedCount(1);
        $this->assertSame($level, DB::transactionLevel());
        $this->assertDatabaseCount('fixture_records', 0);
        $this->mockResolver(
            static fn (mixed $root, array $arguments): Record => Record::query()->create([
                'title' => $arguments['title'],
            ]),
        );
        $input['title'] = 'new successful input after rollback';
        $this->graphQL(
            'mutation ($key: IdempotencyKey! = "c99b2872-a358-452b-b1f7-761d2c46e9be", $input: CreateInput!) { create(input: $input) @idempotent(key: $key) { title } }',
            [
                'input' => $input,
            ],
        )->assertGraphQLErrorFree()->assertJsonPath(
            'data.create.title',
            'new successful input after rollback',
        );
        $this->assertDatabaseCount('fixture_records', 1);
    }

    public function testStoreHandlerFailureRollsBackNativeCreateAndTheClaim(): void
    {
        Exceptions::fake([RuntimeException::class]);
        $this->useCreateSchema();
        $this->handler->failStore = true;
        $input = ['tenant' => 'primary', 'title' => 'rolled back store failure'];
        $level = DB::transactionLevel();

        $this->graphQL(
            'mutation ($key: IdempotencyKey! = "c99b2872-a358-452b-b1f7-761d2c46e9be", $input: CreateInput!) { create(input: $input) @idempotent(key: $key) { id } }',
            [
                'input' => $input,
            ],
        )->assertGraphQLErrorMessage('Internal server error')->assertJsonPath(
            'errors.0.path',
            ['create'],
        );

        Exceptions::assertReported(
            static fn (RuntimeException $exception): bool => 'The fixture payload cannot be stored.' === $exception->getMessage(),
        );
        Exceptions::assertReportedCount(1);
        $this->assertSame($level, DB::transactionLevel());
        $this->assertDatabaseCount('fixture_records', 0);
        $this->handler->failStore = false;
        $input['title'] = 'new successful input after store failure';
        $this->graphQL(
            'mutation ($key: IdempotencyKey! = "c99b2872-a358-452b-b1f7-761d2c46e9be", $input: CreateInput!) { create(input: $input) @idempotent(key: $key) { title } }',
            [
                'input' => $input,
            ],
        )->assertGraphQLErrorFree()->assertJsonPath(
            'data.create.title',
            'new successful input after store failure',
        );
        $this->assertDatabaseCount('fixture_records', 1);
    }

    public function testMissingAuthenticationRejectsADuplicateBeforeInputComparison(): void
    {
        $this->useCreateSchema();
        $input = ['tenant' => 'primary', 'title' => 'authenticated intent'];
        $response = $this->graphQL(
            'mutation ($key: IdempotencyKey! = "c99b2872-a358-452b-b1f7-761d2c46e9be", $input: CreateInput!) { create(input: $input) @idempotent(key: $key) { id } }',
            ['input' => $input],
        )->assertGraphQLErrorFree();
        Auth::logout();
        $this->assertGuest();

        $this->graphQL(
            'mutation ($key: IdempotencyKey! = "c99b2872-a358-452b-b1f7-761d2c46e9be", $input: CreateInput!) { create(input: $input) @idempotent(key: $key) { id } }',
            [
                'input' => [...$input, 'title' => 'changed while unauthenticated'],
            ],
        )->assertGraphQLErrorMessage('Unauthenticated.')->assertJsonPath(
            'data',
            null,
        );

        $this->assertSame(2, $this->handler->scopeCalls);
        $this->assertDatabaseCount('fixture_records', 1);
        $this->actingAs(new User()->forceFill(['id' => 11]));
        $this->graphQL(
            'mutation ($key: IdempotencyKey! = "c99b2872-a358-452b-b1f7-761d2c46e9be", $input: CreateInput!) { create(input: $input) @idempotent(key: $key) { id } }',
            [
                'input' => $input,
            ],
        )->assertGraphQLErrorFree()->assertJsonPath(
            'data.create.id',
            $response->json('data.create.id'),
        );
    }

    #[TestWith(['create(input: $input) { id }'])]
    #[TestWith(['create(input: $input) @idempotent(key: "7cef22fb-cee7-480d-b2ad-840301d46639") { id }'])]
    public function testMergedActiveFieldsRejectMixedKeyPresenceOrDifferentKeys(string $otherField): void
    {
        $this->useCreateSchema();
        $document = 'mutation ($input: CreateInput!) { create(input: $input) @idempotent(key: "c99b2872-a358-452b-b1f7-761d2c46e9be") { id } ...Other } fragment Other on Mutation { ' . $otherField . ' }';

        $this->graphQL($document, ['input' => ['tenant' => 'primary', 'title' => 'conflicting intent']])
            ->assertJsonPath('errors.0.extensions.code', 'IDEMPOTENCY_KEY_CONFLICT');

        $this->assertSame(0, $this->handler->scopeCalls);
        $this->assertDatabaseCount('fixture_records', 0);
        $this->assertDatabaseCount('idempotency_records', 0);
    }

    public function testMatchingInlineAndNamedFragmentsExecuteOneMergedInvocation(): void
    {
        $this->useCreateSchema();
        $document = <<<'GRAPHQL'
            mutation ($key: IdempotencyKey!, $input: CreateInput!) {
                ... on Mutation { create(input: $input) @idempotent(key: $key) { id } }
                ...Again
            }
            fragment Again on Mutation { create(input: $input) @idempotent(key: $key) { title } }
            GRAPHQL;

        $this->graphQL($document, ['key' => self::KEY, 'input' => ['tenant' => 'primary', 'title' => 'merged once']])
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.create.title', 'merged once');

        $this->assertSame(1, $this->handler->scopeCalls);
        $this->assertDatabaseCount('fixture_records', 1);
    }

    #[TestWith(['@skip(if: true)'])]
    #[TestWith(['@include(if: false)'])]
    public function testInactiveMergedFieldsDoNotCreateAKeyConflict(string $condition): void
    {
        $this->useCreateSchema();
        $document = 'mutation ($input: CreateInput!) { create(input: $input) @idempotent(key: "c99b2872-a358-452b-b1f7-761d2c46e9be") { id } ...Inactive ' . $condition . ' } fragment Inactive on Mutation { create(input: $input) @idempotent(key: "7cef22fb-cee7-480d-b2ad-840301d46639") { title } }';

        $this->graphQL($document, ['input' => ['tenant' => 'primary', 'title' => 'active once']])
            ->assertGraphQLErrorFree();

        $this->assertSame(1, $this->handler->scopeCalls);
        $this->assertDatabaseCount('fixture_records', 1);
    }

    private function useCreateSchema(): void
    {
        $this->useSchema(
            <<<'GRAPHQL'
                type Query { noop: Boolean @mock }
                type Mutation {
                    create(input: CreateInput! @spread): Record! @create(model: "Record")
                        @canModel(ability: "create-idempotent-record", model: "Record")
                        @idempotency(operation: "fixture.record.create", handler: "Noldors\\LighthouseIdempotency\\Tests\\Fixtures\\IdempotencyGraphQL\\RecordHandler")
                }
                type Record { id: ID!, title: String @canRoot(ability: "view-idempotent-record") }
                input CreateInput {
                    tenant: String! @drop
                    title: String! @rules(apply: ["unique:fixture_records,title"])
                }
                GRAPHQL,
        );
    }
}
