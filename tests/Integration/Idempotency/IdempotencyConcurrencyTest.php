<?php

declare(strict_types = 1);

namespace Noldors\LighthouseIdempotency\Tests\Integration\Idempotency;

use LogicException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Noldors\LighthouseIdempotency\Tests\TestCase;
use Noldors\LighthouseIdempotency\Services\ExecuteIdempotentOperation;
use Noldors\LighthouseIdempotency\Exceptions\IdempotencyInProgressException;

final class IdempotencyConcurrencyTest extends TestCase
{
    #[TestWith(['same'])]
    #[TestWith(['different'])]
    public function testCompetingConnectionCannotEnterAnActiveIntent(string $otherInput): void
    {
        \config(['database.connections.competitor' => \config('database.connections.pgsql')]);
        $service = $this->application->make(ExecuteIdempotentOperation::class);
        $executions = 0;

        try {
            $result = $service->handle(
                'write',
                'actor:7',
                'a1311d71-3b87-4ce2-b0f6-c930c63b2205',
                ['value' => 'same'],
                function () use ($service, $otherInput, &$executions): array {
                    ++$executions;
                    \config(['lighthouse-idempotency.connection' => 'competitor']);

                    try {
                        $service->handle(
                            'write',
                            'actor:7',
                            'a1311d71-3b87-4ce2-b0f6-c930c63b2205',
                            ['value' => $otherInput],
                            static fn () => self::fail('The competitor must not execute.'),
                            static fn () => self::fail(
                                'An active intent has no outcome to authorize.',
                            ),
                        );
                        self::fail(
                            'The competing connection must receive an in-progress result.',
                        );
                    } catch (IdempotencyInProgressException) {
                        self::assertSame(0, DB::connection('competitor')->transactionLevel());
                    } finally {
                        \config(['lighthouse-idempotency.connection' => null]);
                    }

                    return ['id' => 7];
                },
                static function (): void {},
            );
            $this->assertFalse($result->replayed);
            $replay = $service->handle(
                'write',
                'actor:7',
                'a1311d71-3b87-4ce2-b0f6-c930c63b2205',
                ['value' => 'same'],
                static fn (): never => throw new LogicException('A completed intent must not execute.'),
                static function (): void {},
            );
            $this->assertTrue($replay->replayed);
            $this->assertSame(1, $executions);
            $this->assertDatabaseCount('idempotency_records', 1);
        } finally {
            DB::purge('competitor');
        }
    }
}
