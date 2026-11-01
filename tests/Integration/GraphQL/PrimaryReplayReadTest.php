<?php

declare(strict_types = 1);

namespace Noldors\LighthouseIdempotency\Tests\Integration\GraphQL;

use Illuminate\Http\Request;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Nuwave\Lighthouse\Execution\HttpGraphQLContext;
use Noldors\LighthouseIdempotency\Tests\Fixtures\Record;
use Noldors\LighthouseIdempotency\Tests\Support\GraphqlTestCase;
use Noldors\LighthouseIdempotency\Tests\Fixtures\PrimaryReadRecord;
use Noldors\LighthouseIdempotency\Handlers\StandardIdempotencyHandler;

final class PrimaryReplayReadTest extends GraphqlTestCase
{
    public function testRestoreAndAuthorizationReadPrimaryWhenReadPdoContainsDifferentData(): void
    {
        \config([
            'database.connections.split-probe' => \config('database.connections.pgsql'),
            'database.connections.replica-probe' => \config('database.connections.pgsql'),
            'lighthouse-idempotency.connection' => 'split-probe',
        ]);
        $record = Record::query()->create(['title' => 'primary-authorized']);
        $primary = DB::connection()->getPdo();
        $replica = DB::connection('replica-probe');
        $replica->statement(
            'CREATE TEMP TABLE fixture_records (id bigint PRIMARY KEY, title varchar(255))',
        );
        $replica->table('fixture_records')->insert(['id' => $record->getKey(), 'title' => 'replica-stale']);
        $split = DB::connection('split-probe');
        $split->setPdo($primary);
        $split->setReadPdo($replica->getPdo());
        $this->assertSame(0, $split->transactionLevel());
        $this->assertNotSame($split->getPdo(), $split->getReadPdo());
        $this->assertSame('replica-stale', $replica->table('fixture_records')->value('title'));
        $request = Request::create('/');
        $request->setUserResolver(static fn () => new GenericUser(['id' => 7]));
        $context = new HttpGraphQLContext($request);
        $checked = [];
        Gate::define('primary-view', static function ($actor, PrimaryReadRecord $model) use (&$checked): bool {
            $checked[] = $model->getAttribute('title');

            return $model->getAttribute('title') === 'primary-authorized';
        });

        try {
            $handler = new StandardIdempotencyHandler(PrimaryReadRecord::class, 'primary-view');
            $restored = $handler->restore(['id' => $record->getKey()], [], $context);
            $this->assertInstanceOf(PrimaryReadRecord::class, $restored);
            $this->assertSame('primary-authorized', $restored->getAttribute('title'));
            $this->assertSame(['primary-authorized'], $checked);
        } finally {
            DB::purge('split-probe');
            DB::purge('replica-probe');
        }
    }
}
