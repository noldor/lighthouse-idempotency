<?php

declare(strict_types = 1);

namespace Noldors\LighthouseIdempotency\Tests\Integration\Idempotency;

use RuntimeException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Noldors\LighthouseIdempotency\Tests\TestCase;
use Noldors\LighthouseIdempotency\Services\ExecuteIdempotentOperation;
use Noldors\LighthouseIdempotency\Services\PruneExpiredIdempotencyRecords;

final class ConfiguredStorageTest extends TestCase
{
    public function testConfiguredTableWorksWithPostgresQuotingAndPruning(): void
    {
        Schema::rename('idempotency_records', 'CustomLedger');
        \config(['lighthouse-idempotency.table' => 'CustomLedger']);
        $this->travelTo(\now()->subDay());
        $result = $this->application->make(ExecuteIdempotentOperation::class)->handle(
            'write',
            'actor:7',
            'a1311d71-3b87-4ce2-b0f6-c930c63b2205',
            [],
            static fn (): array => [
                'id' => 1,
            ],
            static function (): void {},
            ttl: 60,
        );
        $this->assertFalse($result->replayed);
        $this->assertDatabaseCount('CustomLedger', 1);
        $this->travelBack();
        $this->assertSame(1, $this->application->make(PruneExpiredIdempotencyRecords::class)->handle(1));
        $this->assertDatabaseCount('CustomLedger', 0);
    }

    public function testConfiguredConnectionOwnsLedgerAndCallbackWithoutChangingTheDefault(): void
    {
        \config(['database.connections.ledger' => \config('database.connections.pgsql')]);
        $other = DB::connection('ledger');
        $other->beginTransaction();

        try {
            Schema::connection('ledger')->create('alternate_ledger', static function (Blueprint $table): void {
                $table->char('scope_hash', 64);
                $table->string('operation');
                $table->uuid('key');
                $table->char('input_hash', 64);
                $table->jsonb('result_payload')->nullable();
                $table->timestampTz('created_at', 6);
                $table->timestampTz('completed_at', 6)->nullable();
                $table->timestampTz('expires_at', 6)->nullable();
                $table->unique([
                    'scope_hash',
                    'operation',
                    'key',
                ]);
            });
            Schema::connection('ledger')->create(
                'alternate_effects',
                static function (Blueprint $table): void {
                    $table->id();
                    $table->string('label');
                },
            );
            \config([
                'lighthouse-idempotency.connection' => 'ledger',
                'lighthouse-idempotency.table' => 'alternate_ledger',
            ]);

            try {
                $this->application->make(ExecuteIdempotentOperation::class)->handle(
                    'write',
                    'actor:7',
                    'a1311d71-3b87-4ce2-b0f6-c930c63b2205',
                    [],
                    static function () use ($other): array {
                        $other->table('alternate_effects')->insert(['label' => 'rollback']);

                        throw new RuntimeException('Rollback on the configured connection.');
                    },
                    static function (): void {},
                );
                $this->fail('The callback must fail.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Rollback on the configured connection.', $exception->getMessage());
            }
            $this->assertSame('pgsql', DB::getDefaultConnection());
            $this->assertSame(0, $other->table('alternate_effects')->count());
            $this->assertSame(0, $other->table('alternate_ledger')->count());
        } finally {
            $other->rollBack();
            DB::purge('ledger');
        }
    }

    public function testDifferentLedgerTablesHaveIndependentAdvisoryIdentities(): void
    {
        DB::statement(
            'CREATE TABLE second_ledger (LIKE idempotency_records INCLUDING ALL)',
        );
        $service = $this->application->make(ExecuteIdempotentOperation::class);
        $service->handle(
            'write',
            'actor:7',
            'a1311d71-3b87-4ce2-b0f6-c930c63b2205',
            [],
            function () use ($service): array {
                \config(['lighthouse-idempotency.table' => 'second_ledger']);
                $nested = $service->handle(
                    'write',
                    'actor:7',
                    'a1311d71-3b87-4ce2-b0f6-c930c63b2205',
                    [],
                    static fn (): array => [
                        'id' => 2,
                    ],
                    static function (): void {},
                );
                self::assertFalse($nested->replayed);
                \config(['lighthouse-idempotency.table' => 'idempotency_records']);

                return ['id' => 1];
            },
            static function (): void {},
        );
        $this->assertDatabaseCount('idempotency_records', 1);
        $this->assertDatabaseCount('second_ledger', 1);
    }
}
