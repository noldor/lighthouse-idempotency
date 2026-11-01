<?php

declare(strict_types = 1);

namespace Noldors\LighthouseIdempotency\Tests\Integration\Idempotency;

use Override;
use LogicException;
use RuntimeException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\Attributes\DataProvider;
use Noldors\LighthouseIdempotency\Tests\TestCase;
use Illuminate\Auth\Access\AuthorizationException;
use Noldors\LighthouseIdempotency\Services\ExecuteIdempotentOperation;
use Noldors\LighthouseIdempotency\Exceptions\IdempotencyInProgressException;
use Noldors\LighthouseIdempotency\Exceptions\IdempotencyInputMismatchException;

final class IdempotencyTest extends TestCase
{
    private const string KEY = 'a1311d71-3b87-4ce2-b0f6-c930c63b2205';

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('idempotency_effects', static function (Blueprint $table): void {
            $table->id();
            $table->string('label');
        });
    }

    public function testSuccessfulResultIsReplayedWithoutRepeatingItsEffect(): void
    {
        $service = $this->application->make(ExecuteIdempotentOperation::class);
        $authorized = [];
        $first = $service->handle(
            'send',
            'actor:7',
            self::KEY,
            ['subject' => 'hello'],
            static function (): array {
                $id = DB::table('idempotency_effects')->insertGetId(['label' => 'sent']);

                return ['result' => ['id' => $id, 'flags' => [true, false, null], 'labels' => ['first', 'second']]];
            },
            static function () use (&$authorized): void {
                $authorized[] = 'unexpected first-execution authorization';
            },
        );
        $this->assertFalse($first->replayed);
        $this->assertSame([], $authorized);
        $replay = $service->handle(
            'send',
            'actor:7',
            self::KEY,
            ['subject' => 'hello'],
            static fn (): never => throw new LogicException(
                'A completed operation must not execute again.',
            ),
            static function (mixed $payload) use (&$authorized): void {
                $authorized[] = $payload;
            },
        );
        $this->assertTrue($replay->replayed);
        $this->assertEquals($first->payload, $replay->payload);
        $this->assertEquals([$first->payload], $authorized);
        $this->assertDatabaseCount('idempotency_effects', 1);
        $this->assertDatabaseCount('idempotency_records', 1);
    }

    public function testNestedObjectOrderAndUuidCaseDoNotChangeTheOperation(): void
    {
        $service = $this->application->make(ExecuteIdempotentOperation::class);
        $service->handle(
            'send',
            'actor:7',
            self::KEY,
            ['recipient' => ['name' => 'Anna', 'email' => 'anna@example.test'], 'subject' => 'hello'],
            static fn (): array => ['id' => 42],
            static function (): void {},
        );
        $replay = $service->handle(
            'send',
            'actor:7',
            \mb_strtoupper(self::KEY),
            ['subject' => 'hello', 'recipient' => ['email' => 'anna@example.test', 'name' => 'Anna']],
            static fn (): never => throw new LogicException(
                'Object key order and IdempotencyKey case must be canonical.',
            ),
            static function (): void {},
        );
        $this->assertTrue($replay->replayed);
        $this->assertSame(['id' => 42], $replay->payload);
        $this->assertDatabaseCount('idempotency_records', 1);
    }

    public function testAnEmptyPayloadIsACompletedResult(): void
    {
        $service = $this->application->make(ExecuteIdempotentOperation::class);
        $first = $service->handle(
            'send',
            'actor:7',
            self::KEY,
            [],
            static fn (): array => [],
            static function (): void {},
        );
        $this->assertFalse($first->replayed);
        $replay = $service->handle(
            'send',
            'actor:7',
            self::KEY,
            [],
            static fn (): never => throw new LogicException(
                'An empty payload must not be mistaken for an incomplete claim.',
            ),
            static function (): void {},
        );
        $this->assertTrue($replay->replayed);
        $this->assertSame([], $replay->payload);
    }

    /**
     * @param array<string, mixed> $firstInput
     * @param array<string, mixed> $changedInput
     */
    #[DataProvider('changedInputs')]
    public function testSignificantInputChangesAreRejected(array $firstInput, array $changedInput): void
    {
        $service = $this->application->make(ExecuteIdempotentOperation::class);
        $service->handle(
            'send',
            'actor:7',
            self::KEY,
            $firstInput,
            static fn (): array => ['id' => 42],
            static function (): void {},
        );

        try {
            $service->handle(
                'send',
                'actor:7',
                self::KEY,
                $changedInput,
                static fn (): never => throw new LogicException('Mismatched input must not execute.'),
                static function (): void {},
            );
            $this->fail(
                'A significant input change must be rejected.',
            );
        } catch (IdempotencyInputMismatchException $exception) {
            $this->assertSame(
                'The idempotency key has already been used with different input.',
                $exception->getMessage(),
            );
        }
        $this->assertDatabaseCount('idempotency_records', 1);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function changedInputs(): iterable
    {
        yield 'scalar value' => [['subject' => 'hello'], ['subject' => 'changed']];

        yield 'list order' => [['recipients' => ['one', 'two']], ['recipients' => ['two', 'one']]];

        yield 'omitted versus explicit null' => [[], ['subject' => null]];
    }

    /**
     * @param array<string, string> $input
     */
    #[DataProvider('replayInputs')]
    public function testReplayAuthorizationRunsBeforeReturningOrDisclosingAnInputMismatch(array $input): void
    {
        $service = $this->application->make(ExecuteIdempotentOperation::class);
        $service->handle(
            'send',
            'actor:7',
            self::KEY,
            ['subject' => 'hello'],
            static fn (): array => ['id' => 42],
            static function (): void {},
        );
        $authorizedPayload = null;

        try {
            $service->handle(
                'send',
                'actor:7',
                self::KEY,
                $input,
                static fn (): never => throw new LogicException('A revoked replay must not execute.'),
                static function (mixed $payload) use (&$authorizedPayload): void {
                    $authorizedPayload = $payload;

                    throw new AuthorizationException('Replay access revoked.');
                },
            );
            $this->fail(
                'A replay must enforce current authorization.',
            );
        } catch (AuthorizationException $exception) {
            $this->assertSame('Replay access revoked.', $exception->getMessage());
        }
        $this->assertSame(['id' => 42], $authorizedPayload);
        $this->assertDatabaseCount('idempotency_records', 1);
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function replayInputs(): iterable
    {
        yield 'matching input' => [['subject' => 'hello']];

        yield 'changed input' => [['subject' => 'changed']];
    }

    public function testOperationAndActorScopesCanReuseTheSameKeyIndependently(): void
    {
        $service = $this->application->make(ExecuteIdempotentOperation::class);
        $tuples = [['send', 'actor:7'], ['send', 'actor:8'], ['archive', 'actor:7']];

        foreach ($tuples as [$operation, $scope]) {
            $result = $service->handle(
                $operation,
                $scope,
                self::KEY,
                [],
                static function () use ($operation, $scope): array {
                    $id = DB::table('idempotency_effects')->insertGetId(['label' => $operation . '/' . $scope]);

                    return ['id' => $id];
                },
                static function (): void {},
            );
            $this->assertFalse($result->replayed);
        }
        $this->assertDatabaseCount('idempotency_effects', 3);
        $this->assertDatabaseCount('idempotency_records', 3);
    }

    public function testFailedExecutionRollsBackItsClaimAndEffectBeforeRetry(): void
    {
        $service = $this->application->make(ExecuteIdempotentOperation::class);

        try {
            $service->handle(
                'send',
                'actor:7',
                self::KEY,
                [],
                static function (): array {
                    DB::table('idempotency_effects')->insert(['label' => 'must roll back']);

                    throw new RuntimeException('Delivery failed.');
                },
                static function (): void {},
            );
            $this->fail('The execution failure must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Delivery failed.', $exception->getMessage());
        }
        $this->assertDatabaseCount('idempotency_effects', 0);
        $this->assertDatabaseCount('idempotency_records', 0);
        $retry = $service->handle(
            'send',
            'actor:7',
            self::KEY,
            [],
            static function (): array {
                $id = DB::table('idempotency_effects')->insertGetId(['label' => 'retry succeeded']);

                return ['id' => $id];
            },
            static function (): void {},
        );
        $this->assertFalse($retry->replayed);
        $this->assertDatabaseCount('idempotency_effects', 1);
        $this->assertDatabaseCount('idempotency_records', 1);
    }

    public function testAmbientRollbackRemovesTheBusinessEffectAndCompletedRecordTogether(): void
    {
        $service = $this->application->make(ExecuteIdempotentOperation::class);
        $level = DB::transactionLevel();
        DB::beginTransaction();

        try {
            $result = $service->handle(
                'send',
                'actor:7',
                self::KEY,
                [],
                static function (): array {
                    $id = DB::table('idempotency_effects')->insertGetId(['label' => 'ambient write']);

                    return ['id' => $id];
                },
                static function (): void {},
            );
            $this->assertFalse($result->replayed);
            $this->assertDatabaseCount('idempotency_effects', 1);
            $this->assertDatabaseCount('idempotency_records', 1);
        } finally {
            DB::rollBack($level);
        }
        $this->assertSame($level, DB::transactionLevel());
        $this->assertDatabaseCount('idempotency_effects', 0);
        $this->assertDatabaseCount('idempotency_records', 0);
        $retry = $service->handle(
            'send',
            'actor:7',
            self::KEY,
            [],
            static fn (): array => ['id' => 99],
            static function (): void {},
        );
        $this->assertFalse($retry->replayed);
    }

    public function testRecursiveExecutionRejectsTheIncompleteClaimAndAllowsRetryAfterRollback(): void
    {
        $service = $this->application->make(ExecuteIdempotentOperation::class);

        try {
            $service->handle(
                'send',
                'actor:7',
                self::KEY,
                [],
                static function () use ($service): array {
                    DB::table('idempotency_effects')->insert(['label' => 'recursive effect']);
                    $service->handle(
                        'send',
                        'actor:7',
                        self::KEY,
                        [],
                        static fn () => self::fail(
                            'Recursive execution must not enter the handler.',
                        ),
                        static fn () => self::fail(
                            'An incomplete claim has no replay payload to authorize.',
                        ),
                    );

                    return ['id' => 42];
                },
                static function (): void {},
            );
            $this->fail(
                'Recursive execution must reject an incomplete claim.',
            );
        } catch (IdempotencyInProgressException $exception) {
            $this->assertSame(
                'An operation with this idempotency key is already in progress.',
                $exception->getMessage(),
            );
        }
        $this->assertDatabaseCount('idempotency_effects', 0);
        $this->assertDatabaseCount('idempotency_records', 0);
        $retry = $service->handle(
            'send',
            'actor:7',
            self::KEY,
            [],
            static fn (): array => ['id' => 99],
            static function (): void {},
        );
        $this->assertFalse($retry->replayed);
    }
}
