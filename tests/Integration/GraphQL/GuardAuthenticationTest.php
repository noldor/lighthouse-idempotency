<?php

declare(strict_types = 1);

namespace Noldors\LighthouseIdempotency\Tests\Integration\GraphQL;

use LogicException;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\TestWith;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Noldors\LighthouseIdempotency\Tests\Support\GraphqlTestCase;
use Noldors\LighthouseIdempotency\Tests\Fixtures\Guards\GuardDirective;

final class GuardAuthenticationTest extends GraphqlTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        GuardDirective::$authenticationCount = 0;
        GuardDirective::$allowed = true;
        \config([
            'lighthouse.guards' => ['web-probe', 'api-probe'],
            'auth.guards.web-probe' => ['driver' => 'web-probe'],
            'auth.guards.api-probe' => ['driver' => 'api-probe'],
            'lighthouse.namespaces.directives' => ['Noldors\\LighthouseIdempotency\\Tests\\Fixtures\\Guards'],
        ]);
        Auth::viaRequest('web-probe', static fn (): GenericUser => new GenericUser(['id' => 'actor-A']));
        Auth::viaRequest('api-probe', static fn (): GenericUser => new GenericUser(['id' => 'actor-B']));
        Gate::define('guard-replay', static fn (): bool => true);
    }

    protected function tearDown(): void
    {
        GuardDirective::$authenticationCount = 0;
        GuardDirective::$allowed = true;
        parent::tearDown();
    }

    #[TestWith(['type Mutation { actions: ProtectedMutations! @guard(with: ["api-probe"]) @namespaced }'])]
    #[TestWith(['type Mutation @guard(with: ["api-probe"]) { actions: ProtectedMutations! @namespaced }'])]
    #[TestWith([
        'type Mutation { noop: Boolean @mock } extend type Mutation @guard(with: ["api-probe"]) { actions: ProtectedMutations! @namespaced }',
    ])]
    public function testNamespaceGuardAuthenticatesOncePerRequestBeforeFreshExecutionAndReplay(string $mutation): void
    {
        $this->useSchema(
            $mutation . <<<'GRAPHQL'

                type Query { noop: Boolean @mock }
                type ProtectedMutations {
                    execute(input: String!): String! @mock @idempotency(replayAbility: "guard-replay")
                }
                GRAPHQL,
        );
        $executions = 0;
        $this->mockResolver(
            static function (mixed $root, array $arguments, GraphQLContext $context) use (&$executions): string {
                ++$executions;
                $actor = $context->user() ?? throw new LogicException('The guard must authenticate an actor.');
                $id = $actor->getAuthIdentifier();

                if (! \is_string($id) && ! \is_int($id)) {
                    throw new LogicException('The test actor must have a scalar identifier.');
                }

                return (string) $id;
            },
        );
        $document = <<<'GRAPHQL'
            mutation($key: IdempotencyKey!, $input: String!) {
                actions {
                    execute(input: $input) @idempotent(key: $key)
                }
            }
            GRAPHQL;
        $variables = ['key' => '11111111-1111-1111-1111-111111111111', 'input' => 'original intent'];

        $this->graphQL($document, $variables)
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.actions.execute', 'actor-B');
        $this->assertSame(1, GuardDirective::$authenticationCount);
        $this->assertDatabaseCount('idempotency_records', 1);
        $record = DB::table('idempotency_records')->first();
        $this->assertNotNull($record);
        $expectedScope = \hash('sha256', \json_encode([GenericUser::class, 'actor-B'], \JSON_THROW_ON_ERROR));
        $this->assertSame($expectedScope, $record->scope_hash);

        Auth::forgetGuards();
        $this->graphQL($document, $variables)
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.actions.execute', 'actor-B');
        $this->assertSame(2, GuardDirective::$authenticationCount);
        $this->assertDatabaseCount('idempotency_records', 1);

        GuardDirective::$allowed = false;
        Auth::forgetGuards();
        $this->graphQL($document, [...$variables, 'input' => 'changed after authentication was revoked'])
            ->assertGraphQLErrorMessage('Custom guard authentication revoked.')
            ->assertJsonCount(1, 'errors')
            ->assertJsonPath('errors.0.path', ['actions'])
            ->assertJsonPath('data', null);
        $this->assertSame(3, GuardDirective::$authenticationCount);
        $this->assertDatabaseCount('idempotency_records', 1);
        $this->assertEquals($record, DB::table('idempotency_records')->first());
        $this->assertSame(1, $executions);
    }

    public function testOptionalKeylessExecutionAuthenticatesOnceOnEveryRequestWithoutCreatingLedgerRows(): void
    {
        $this->useSchema(
            <<<'GRAPHQL'
                type Query { noop: Boolean @mock }
                type Mutation { actions: ProtectedMutations! @guard(with: ["api-probe"]) @namespaced }
                type ProtectedMutations {
                    execute: String! @mock @idempotency(required: false, replayAbility: "guard-replay")
                }
                GRAPHQL,
        );
        $executions = 0;
        $this->mockResolver(
            static function () use (&$executions): string {
                ++$executions;

                return 'unkeyed outcome';
            },
        );
        $document = 'mutation { actions { execute } }';

        $this->graphQL($document)
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.actions.execute', 'unkeyed outcome');
        $this->assertSame(1, GuardDirective::$authenticationCount);
        $this->assertDatabaseCount('idempotency_records', 0);

        Auth::forgetGuards();
        $this->graphQL($document)
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.actions.execute', 'unkeyed outcome');
        $this->assertSame(2, GuardDirective::$authenticationCount);
        $this->assertSame(2, $executions);
        $this->assertDatabaseCount('idempotency_records', 0);
    }

    public function testNamespaceGuardRejectsAnActorFromAnotherGuardBeforeCreatingALedgerRow(): void
    {
        Auth::viaRequest('api-probe', static fn (): null => null);
        Auth::forgetGuards();
        $this->useSchema(
            <<<'GRAPHQL'
                type Query { noop: Boolean @mock }
                type Mutation { actions: ProtectedMutations! @guard(with: ["api-probe"]) @namespaced }
                type ProtectedMutations {
                    execute: String! @mock @idempotency(replayAbility: "guard-replay")
                }
                GRAPHQL,
        );
        $this->mockResolverExpects($this->never());

        $this->graphQL(
            'mutation { actions { execute @idempotent(key: "11111111-1111-1111-1111-111111111111") } }',
        )->assertGraphQLErrorMessage('Unauthenticated.')->assertJsonCount(1, 'errors')
            ->assertJsonPath('errors.0.path', ['actions'])
            ->assertJsonPath('data', null);
        $this->assertSame(1, GuardDirective::$authenticationCount);
        $this->assertDatabaseCount('idempotency_records', 0);
    }
}
