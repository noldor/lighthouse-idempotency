<?php

declare(strict_types = 1);

namespace Noldors\LighthouseIdempotency\Tests\Integration\Idempotency;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use Noldors\LighthouseIdempotency\Tests\TestCase;

final class PruneIdempotencyRecordsTest extends TestCase
{
    public function testCommandDeletesOnlyCompletedExpiredRecordsInBoundedBatchesAndCanRepeat(): void
    {
        $this->travelTo(Carbon::parse('2026-11-01T10:00:00Z'));
        $this->record('expired-before', '2026-11-01T09:59:59Z');
        $this->record('expired-at-boundary', '2026-11-01T10:00:00Z');
        $this->record('unexpired', '2026-11-01T10:00:00.000001Z');
        $this->record('incomplete', '2026-11-01T09:00:00Z', completed: false);
        $this->record('without-expiry', null);

        $this->assertSame(0, Artisan::call('idempotency:prune', ['--batch' => '1']));
        $this->assertDatabaseCount('idempotency_records', 3);
        $this->assertDatabaseMissing('idempotency_records', ['operation' => 'expired-before']);
        $this->assertDatabaseMissing('idempotency_records', ['operation' => 'expired-at-boundary']);
        $this->assertDatabaseHas('idempotency_records', ['operation' => 'unexpired']);
        $this->assertDatabaseHas('idempotency_records', ['operation' => 'incomplete']);
        $this->assertDatabaseHas('idempotency_records', ['operation' => 'without-expiry']);
        $this->assertSame(0, Artisan::call('idempotency:prune', ['--batch' => '1']));
        $this->assertDatabaseCount('idempotency_records', 3);
    }

    public function testCleanupUsesBoundedDatabaseRoundTripsForEachBatch(): void
    {
        for ($index = 0; 9 > $index; ++$index) {
            $this->record('expired-' . $index, '2020-01-01T00:00:00Z');
        }
        $queries = 0;
        DB::listen(
            static function (\Illuminate\Database\Events\QueryExecuted $query) use (&$queries): void {
                if (\str_contains($query->sql, 'idempotency_records')) {
                    ++$queries;
                }
            },
        );

        $this->assertSame(0, Artisan::call('idempotency:prune', ['--batch' => '4']));
        $this->assertLessThanOrEqual(4, $queries);
        $this->assertDatabaseCount('idempotency_records', 0);
    }

    #[DataProvider('invalidBatches')]
    public function testInvalidBatchSizeFailsWithoutDeletingRecords(string $batch): void
    {
        $this->record('expired', '2020-01-01T00:00:00Z');
        $this->assertSame(1, Artisan::call('idempotency:prune', ['--batch' => $batch]));
        $this->assertDatabaseCount('idempotency_records', 1);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidBatches(): iterable
    {
        yield 'zero' => ['0'];

        yield 'negative' => ['-1'];

        yield 'fraction' => ['1.5'];

        yield 'text' => ['many'];

        yield 'too large' => ['10001'];
    }

    public function testCleanupKeepsOneCutoffEvenWhenTimeMovesBetweenBatches(): void
    {
        $this->travelTo(Carbon::parse('2026-11-01T10:00:00Z'));
        $this->record('expired', '2026-11-01T09:00:00Z');
        $this->record('future-at-start', '2026-11-01T11:00:00Z');
        $cutoffs = [];
        DB::listen(
            function (\Illuminate\Database\Events\QueryExecuted $query) use (&$cutoffs): void {
                if (\str_contains($query->sql, 'WITH expired')) {
                    $cutoffs[] = $query->bindings[0];
                    $this->travelTo(Carbon::parse('2026-11-02T10:00:00Z'));
                }
            },
        );
        $deleted = $this->application->make(
            \Noldors\LighthouseIdempotency\Services\PruneExpiredIdempotencyRecords::class,
        )->handle(1);
        $this->assertSame(1, $deleted);
        $this->assertCount(2, $cutoffs);
        $this->assertSame($cutoffs[0], $cutoffs[1]);
        $this->assertDatabaseHas('idempotency_records', ['operation' => 'future-at-start']);
    }

    public function testCleanupSkipsRowsLockedByAnotherRealConnectionAndCanDeleteThemLater(): void
    {
        \config([
            'database.connections.prune-primary' => \config('database.connections.pgsql'),
            'database.connections.prune-lock' => \config('database.connections.pgsql'),
        ]);
        $primary = DB::connection('prune-primary');
        $locker = DB::connection('prune-lock');
        $table = 'prune_probe_' . \bin2hex(\random_bytes(8));

        try {
            \Illuminate\Support\Facades\Schema::connection('prune-primary')->create(
                $table,
                static function (\Illuminate\Database\Schema\Blueprint $table): void {
                    $table->char('scope_hash', 64);
                    $table->string('operation');
                    $table->uuid('key');
                    $table->char('input_hash', 64);
                    $table->jsonb('result_payload')->nullable();
                    $table->timestampTz('created_at', 6);
                    $table->timestampTz('completed_at', 6)->nullable();
                    $table->timestampTz('expires_at', 6)->nullable();
                    $table->unique(['scope_hash', 'operation', 'key']);
                },
            );

            foreach (['locked', 'available'] as $operation) {
                $primary->table($table)->insert([
                    'scope_hash' => \hash('sha256', 'actor:7'),
                    'operation' => $operation,
                    'key' => '11111111-1111-1111-1111-111111111111',
                    'input_hash' => \hash('sha256', '[]'),
                    'result_payload' => '{"id":7}',
                    'created_at' => '2020-01-01T00:00:00Z',
                    'completed_at' => '2020-01-01T00:00:00Z',
                    'expires_at' => '2020-01-02T00:00:00Z',
                ]);
            }
            $locker->beginTransaction();
            $locker->table($table)->where('operation', 'locked')->lockForUpdate()->first();
            $primary->statement("SET statement_timeout TO '1s'");
            \config(['lighthouse-idempotency.connection' => 'prune-primary', 'lighthouse-idempotency.table' => $table]);
            $prune = $this->application->make(
                \Noldors\LighthouseIdempotency\Services\PruneExpiredIdempotencyRecords::class,
            );
            $this->assertSame(1, $prune->handle(1));
            $this->assertSame('locked', $primary->table($table)->value('operation'));
            $locker->rollBack();
            $this->assertSame(1, $prune->handle(1));
            $this->assertSame(0, $primary->table($table)->count());
        } finally {
            if ($locker->transactionLevel() > 0) {
                $locker->rollBack();
            }
            \Illuminate\Support\Facades\Schema::connection('prune-primary')->dropIfExists($table);
            DB::purge('prune-lock');
            DB::purge('prune-primary');
        }
    }

    private function record(string $operation, string|null $expiresAt, bool $completed = true): void
    {
        DB::table('idempotency_records')->insert([
            'scope_hash' => \hash('sha256', 'actor:7'),
            'operation' => $operation,
            'key' => 'a1311d71-3b87-4ce2-b0f6-c930c63b2205',
            'input_hash' => \hash('sha256', '[]'),
            'result_payload' => $completed ? '{"id":42}' : null,
            'created_at' => '2020-01-01T00:00:00Z',
            'completed_at' => $completed ? '2020-01-01T00:00:00Z' : null,
            'expires_at' => $expiresAt,
        ]);
    }
}
