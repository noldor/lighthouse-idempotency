<?php

declare(strict_types = 1);

namespace Noldors\LighthouseIdempotency\Tests\Integration\Idempotency;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Artisan;
use Noldors\LighthouseIdempotency\Tests\TestCase;

final class MigrationGenerationTest extends TestCase
{
    public function testGeneratedMigrationFreezesItsUpAndDownTargets(): void
    {
        $directory = \sys_get_temp_dir() . '/lighthouse-idempotency-migration-' . \bin2hex(\random_bytes(8));
        $this->application->useDatabasePath($directory);
        \config(['lighthouse-idempotency.table' => 'generated_ledger']);

        try {
            $this->assertSame(0, Artisan::call('idempotency:table'));
            $paths = \glob($directory . '/migrations/*.php');
            $this->assertIsArray($paths);
            $this->assertCount(1, $paths);
            $migration = require $paths[0];
            $this->assertInstanceOf(\Illuminate\Database\Migrations\Migration::class, $migration);
            $this->assertTrue(\method_exists($migration, 'up'));
            $this->assertTrue(\method_exists($migration, 'down'));
            \config(['lighthouse-idempotency.table' => 'changed_after_generation']);
            $migration->up();
            $this->assertTrue(Schema::hasTable('generated_ledger'));
            $this->assertFalse(
                Schema::hasTable('changed_after_generation'),
            );
            $migration->down();
            $this->assertFalse(Schema::hasTable('generated_ledger'));
            $this->assertSame('pgsql', $migration->getConnection());
            \config(['lighthouse-idempotency.table' => 'generated_ledger']);
            $this->assertSame(1, Artisan::call('idempotency:table'));
        } finally {
            new Filesystem()->deleteDirectory($directory);
        }
    }
}
