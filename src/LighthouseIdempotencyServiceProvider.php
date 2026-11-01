<?php

declare(strict_types = 1);

namespace Noldors\LighthouseIdempotency;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Nuwave\Lighthouse\Events\StartExecution;
use Nuwave\Lighthouse\Events\RegisterDirectiveNamespaces;
use Noldors\LighthouseIdempotency\Console\CreateIdempotencyTable;
use Noldors\LighthouseIdempotency\Console\PruneIdempotencyRecords;
use Noldors\LighthouseIdempotency\Execution\PrepareFieldResolvers;

final class LighthouseIdempotencyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/lighthouse-idempotency.php', 'lighthouse-idempotency');
        $this->app->singleton(PrepareFieldResolvers::class);
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__ . '/../lang', 'lighthouse-idempotency');
        Event::listen(RegisterDirectiveNamespaces::class, static fn (): string => __NAMESPACE__ . '\\Directives');
        // Run after providers' eager boot listeners so replay stays outside other resolver wrappers.
        $this->app->booted(static function (): void {
            Event::listen(StartExecution::class, PrepareFieldResolvers::class);
        });

        if ($this->app->runningInConsole()) {
            $this->commands([CreateIdempotencyTable::class, PruneIdempotencyRecords::class]);
            $this->publishes([
                __DIR__ . '/../config/lighthouse-idempotency.php' => \config_path('lighthouse-idempotency.php'),
            ], 'lighthouse-idempotency-config');
            $this->publishes([
                __DIR__ . '/../resources/graphql/idempotency.graphql' => \base_path('graphql/idempotency.graphql'),
            ], 'lighthouse-idempotency-schema');
            $this->publishes(
                [__DIR__ . '/../lang' => \lang_path('vendor/lighthouse-idempotency')],
                'lighthouse-idempotency-translations',
            );
            $this->publishes([
                __DIR__ . '/../database/migrations/create_idempotency_records_table.php.stub' => \database_path('migration-stubs/create_idempotency_records_table.php.stub'),
            ], 'lighthouse-idempotency-migrations');
        }
    }
}
