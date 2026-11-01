<?php

declare(strict_types = 1);

namespace Noldors\LighthouseIdempotency\Console;

use Illuminate\Support\Str;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Noldors\LighthouseIdempotency\Services\IdempotencyDatabase;

final class CreateIdempotencyTable extends Command
{
    protected $signature = 'idempotency:table';

    protected $description = 'Generate an idempotency ledger migration with fixed connection and table targets';

    public function handle(Filesystem $files, IdempotencyDatabase $database): int
    {
        $connection = $database->connection()->getName();
        $table = $database->table();
        $directory = \database_path('migrations');
        $suffix = 'create_' . Str::snake(
            (string) \preg_replace('/[^A-Za-z0-9_]/', '_', $table),
        ) . '_table';

        if ($files->glob($directory . '/*_' . $suffix . '.php') !== []) {
            $this->error(
                (string) \__('lighthouse-idempotency::messages.migration_exists'),
            );

            return self::FAILURE;
        }
        $files->ensureDirectoryExists($directory);
        $path = $directory . '/' . \date('Y_m_d_His') . '_' . $suffix . '.php';
        $stub = $files->get(
            __DIR__ . '/../../database/migrations/create_idempotency_records_table.php.stub',
        );
        $files->put($path, \str_replace(['{{ connection }}', '{{ table }}'], [
            \var_export($connection, true),
            \var_export($table, true),
        ], $stub));
        $this->info(
            (string) \__('lighthouse-idempotency::messages.migration_created', ['path' => $path]),
        );

        return self::SUCCESS;
    }
}
