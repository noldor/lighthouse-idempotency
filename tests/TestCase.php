<?php

declare(strict_types = 1);

namespace Noldors\LighthouseIdempotency\Tests;

use LogicException;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Nuwave\Lighthouse\LighthouseServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Noldors\LighthouseIdempotency\LighthouseIdempotencyServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected Application $application;

    protected function setUp(): void
    {
        parent::setUp();
        $this->application = $this->app ?? throw new LogicException('The test application must be bootstrapped.');
        DB::beginTransaction();
        Schema::create('idempotency_records', static function (Blueprint $table): void {
            $table->char('scope_hash', 64);
            $table->string('operation');
            $table->uuid('key');
            $table->char('input_hash', 64);
            $table->jsonb('result_payload')->nullable();
            $table->timestampTz('created_at', 6);
            $table->timestampTz('completed_at', 6)->nullable();
            $table->timestampTz('expires_at', 6)->nullable()->index();
            $table->unique(['scope_hash', 'operation', 'key']);
        });
    }

    protected function tearDown(): void
    {
        if (null !== $this->app) {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [
            LighthouseServiceProvider::class,
            \Nuwave\Lighthouse\Auth\AuthServiceProvider::class,
            \Nuwave\Lighthouse\Validation\ValidationServiceProvider::class,
            \Nuwave\Lighthouse\GlobalId\GlobalIdServiceProvider::class,
            LighthouseIdempotencyServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('database.connections.pgsql', [
            'driver' => 'pgsql',
            'host' => (\getenv('DB_HOST') ?: 'postgres'),
            'port' => (\getenv('DB_PORT') ?: '5432'),
            'database' => (\getenv('DB_DATABASE') ?: 'lighthouse_idempotency_testing'),
            'username' => (\getenv('DB_USERNAME') ?: 'packages'),
            'password' => (\getenv('DB_PASSWORD') ?: 'packages'),
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ]);
        $app['config']->set('lighthouse.schema_cache.enable', false);
        $app['config']->set('lighthouse.namespaces.models', ['Noldors\\LighthouseIdempotency\\Tests\\Fixtures']);
    }
}
