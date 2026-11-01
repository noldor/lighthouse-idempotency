<?php

declare(strict_types = 1);

namespace Noldors\LighthouseIdempotency\Tests\Integration\Idempotency;

use LogicException;
use RuntimeException;
use InvalidArgumentException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Noldors\LighthouseIdempotency\Tests\TestCase;
use Noldors\LighthouseIdempotency\Services\ExecuteIdempotentOperation;

final class IdempotencyRetentionTest extends TestCase
{
    private const string KEY = 'a1311d71-3b87-4ce2-b0f6-c930c63b2205';

    public function testDefaultRetentionStartsAtSuccessfulCallbackCompletion(): void
    {
        $this->travelTo(
            Carbon::parse('2026-11-01T10:00:00.123456Z'),
        );
        $this->application->make(ExecuteIdempotentOperation::class)->handle(
            'send',
            'actor:7',
            self::KEY,
            [],
            function (): array {
                $this->travelTo(
                    Carbon::parse('2026-11-01T10:01:00.654321Z'),
                );

                return ['id' => 42];
            },
            static function (): void {},
        );

        /** @var object{completed_at: string, expires_at: string, result_payload: string}|null $record */
        $record = DB::table('idempotency_records')->first();
        $this->assertNotNull($record);
        $this->assertSame('2026-11-01 10:01:00.654321', Carbon::parse($record->completed_at)->format('Y-m-d H:i:s.u'));
        $this->assertSame('2026-11-04 10:01:00.654321', Carbon::parse($record->expires_at)->format('Y-m-d H:i:s.u'));
        $this->assertSame(['id' => 42], \json_decode($record->result_payload, true, flags: \JSON_THROW_ON_ERROR));
    }

    public function testReplayBeforeExpiryDoesNotSlideOrReplaceTheStoredTtl(): void
    {
        $this->travelTo(
            Carbon::parse('2026-11-01T10:00:00.123456Z'),
        );
        $service = $this->application->make(ExecuteIdempotentOperation::class);
        $service->handle(
            'send',
            'actor:7',
            self::KEY,
            [],
            static fn (): array => ['id' => 42],
            static function (): void {},
            ttl: 60,
        );
        $this->travelTo(
            Carbon::parse('2026-11-01T10:01:00.123455Z'),
        );
        $replay = $service->handle(
            'send',
            'actor:7',
            self::KEY,
            [],
            static fn (): never => throw new LogicException('An unexpired result must replay.'),
            static function (): void {},
            ttl: 86_400,
        );
        $this->assertTrue($replay->replayed);
        $this->assertSame(['id' => 42], $replay->payload);
        $this->assertSame('2026-11-01 10:01:00.123456', $this->storedExpiry());
    }

    public function testAtExactExpiryTheSameKeyAcceptsNewInputAndRunsFreshAuthorizationOnly(): void
    {
        $this->travelTo(Carbon::parse('2026-11-01T10:00:00Z'));
        $service = $this->application->make(ExecuteIdempotentOperation::class);
        $service->handle(
            'send',
            'actor:7',
            self::KEY,
            ['subject' => 'old'],
            static fn (): array => ['id' => 42],
            static function (): void {},
            ttl: 60,
        );
        $this->travelTo(Carbon::parse('2026-11-01T10:01:00Z'));
        $fresh = $service->handle(
            'send',
            'actor:7',
            self::KEY,
            ['subject' => 'new'],
            static fn (): array => ['id' => 99],
            static fn (): never => throw new LogicException('An expired outcome must not require replay authorization.'),
            ttl: 120,
        );
        $this->assertFalse($fresh->replayed);
        $this->assertSame(['id' => 99], $fresh->payload);
        $this->assertDatabaseCount('idempotency_records', 1);
        $this->assertSame('2026-11-01 10:03:00.000000', $this->storedExpiry());
    }

    public function testFailureAfterExpiryRestoresTheExpiredRecordAndAllowsAnotherFreshAttempt(): void
    {
        $this->travelTo(Carbon::parse('2026-11-01T10:00:00Z'));
        $service = $this->application->make(ExecuteIdempotentOperation::class);
        $service->handle(
            'send',
            'actor:7',
            self::KEY,
            [],
            static fn (): array => ['id' => 42],
            static function (): void {},
            ttl: 1,
        );
        $old = DB::table('idempotency_records')->first();
        $this->travelTo(Carbon::parse('2026-11-01T10:00:02Z'));

        try {
            $service->handle(
                'send',
                'actor:7',
                self::KEY,
                ['changed' => true],
                static fn (): never => throw new RuntimeException('Fresh validation denied.'),
                static fn (): never => throw new LogicException('Expired replay authorization must not run.'),
            );
            $this->fail('The callback failure must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Fresh validation denied.', $exception->getMessage());
        }
        $this->assertEquals($old, DB::table('idempotency_records')->first());
        $fresh = $service->handle(
            'send',
            'actor:7',
            self::KEY,
            ['changed' => true],
            static fn (): array => [
                'id' => 99,
            ],
            static function (): void {},
        );
        $this->assertFalse($fresh->replayed);
        $this->assertSame(['id' => 99], $fresh->payload);
    }

    #[DataProvider('invalidTtls')]
    public function testNonpositiveRetentionIsRejectedBeforeExecution(int $ttl): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Idempotency TTL must be a positive number of seconds.',
        );
        $this->application->make(ExecuteIdempotentOperation::class)->handle(
            'send',
            'actor:7',
            self::KEY,
            [],
            static fn (): never => throw new LogicException('Invalid retention must not execute.'),
            static function (): void {},
            $ttl,
        );
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidTtls(): iterable
    {
        yield 'zero' => [0];

        yield 'negative' => [-1];
    }

    private function storedExpiry(): string
    {
        $expiry = DB::table('idempotency_records')->value('expires_at');
        $this->assertIsString($expiry);

        return Carbon::parse($expiry)->format('Y-m-d H:i:s.u');
    }
}
