<?php

declare(strict_types = 1);

namespace Noldors\LighthouseIdempotency\Tests\Integration\GraphQL;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\TestWith;
use Noldors\LighthouseIdempotency\Tests\Support\GraphqlTestCase;
use Noldors\LighthouseIdempotency\Tests\Fixtures\IdempotencyGraphQL\RecordHandler;

final class PackageConfigurationTest extends GraphqlTestCase
{
    private const string KEY = 'c99b2872-a358-452b-b1f7-761d2c46e9be';

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(new User()->forceFill(['id' => 7]));
        Gate::define('replay', static fn (): bool => true);
        Gate::define('replay-idempotent-record', static fn (): bool => true);
    }

    #[TestWith(['', 17])]
    #[TestWith(['ttl: null,', 17])]
    #[TestWith(['ttl: 41,', 41])]
    public function testTtlUsesConfigForOmittedOrNullAndHonorsFieldOverride(string $option, int $seconds): void
    {
        \config(['lighthouse-idempotency.default_ttl' => 17]);
        $this->travelTo(Carbon::parse('2026-11-01T10:00:00Z'));
        $this->useSchema(
            'type Query { noop: Boolean @mock } type Mutation { value: String @mock @idempotency(' . $option . ' replayAbility: "replay") }',
        );
        $this->mockResolver(static fn (): string => 'result');
        $this->graphQL(
            'mutation { value @idempotent(key: "' . self::KEY . '") }',
        )->assertGraphQLErrorFree();

        /** @var object{completed_at:string, expires_at:string}|null $record */
        $record = DB::table('idempotency_records')->first();
        $this->assertNotNull($record);
        $this->assertSame(
            $seconds,
            (int) Carbon::parse($record->completed_at)->diffInSeconds(Carbon::parse($record->expires_at)),
        );
    }

    public function testConfigDefaultHandlerIsTreatedAsCustomWithoutStandardOptions(): void
    {
        \config(['lighthouse-idempotency.default_handler' => RecordHandler::class]);
        $this->useSchema(
            'type Query { noop: Boolean @mock } type Mutation { create(input: CreateInput! @spread): Record! @create(model: "Record") @idempotency } type Record { id: ID!, title: String } input CreateInput { tenant: String! @drop, title: String! }',
        );
        $document = 'mutation { create(input: {tenant: "one",title: "stored"}) @idempotent(key: "' . self::KEY . '") { id } }';
        $first = $this->graphQL($document)->assertGraphQLErrorFree();
        $this->graphQL($document)->assertGraphQLErrorFree()->assertJsonPath(
            'data.create.id',
            $first->json('data.create.id'),
        );
        $this->assertDatabaseCount('fixture_records', 1);
    }

    #[TestWith([''])]
    #[TestWith(['required: null,'])]
    public function testOptionalConfigRunsWithoutKeyAndWithoutLedger(string $option): void
    {
        \config(['lighthouse-idempotency.default_required' => false]);
        $this->useSchema(
            'type Query { noop: Boolean @mock } type Mutation { value: String @mock @idempotency(' . $option . ' replayAbility: "replay") }',
        );
        $this->mockResolver(static fn (): string => 'result');
        $this->graphQL('mutation { value }')->assertGraphQLErrorFree()->assertJsonPath('data.value', 'result');
        $this->assertDatabaseCount('idempotency_records', 0);
    }

    public function testPublicMessagesUsePackageTranslationsAndHostOverrides(): void
    {
        $this->useSchema(
            'type Query { noop: Boolean @mock } type Mutation { value: String @mock @idempotency(replayAbility: "replay") }',
        );
        $this->mockResolverExpects($this->never());
        $this->graphQL('mutation { value }')->assertGraphQLErrorMessage(
            'An idempotency key is required for this operation.',
        );

        $loader = $this->application->make(
            \Illuminate\Translation\Translator::class,
        )->getLoader();
        $this->assertInstanceOf(\Illuminate\Translation\FileLoader::class, $loader);
        $loader->addPath(__DIR__ . '/../../Fixtures/lang');
        $this->application->setLocale('ru');
        $this->graphQL('mutation { value }')->assertGraphQLErrorMessage('Host translated message.')->assertJsonPath(
            'errors.0.extensions.code',
            'IDEMPOTENCY_KEY_REQUIRED',
        );
    }
}
