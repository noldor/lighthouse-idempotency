<?php

declare(strict_types = 1);

namespace Noldors\LighthouseIdempotency\Tests\Support;

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Nuwave\Lighthouse\Testing\MocksResolvers;
use Nuwave\Lighthouse\Testing\UsesTestSchema;
use Noldors\LighthouseIdempotency\Tests\TestCase;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Nuwave\Lighthouse\Testing\TestingServiceProvider;

abstract class GraphqlTestCase extends TestCase
{
    use MakesGraphQLRequests;
    use MocksResolvers;
    use UsesTestSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->application->register(TestingServiceProvider::class);
        Schema::create('fixture_records', static function (Blueprint $table): void {
            $table->id();
            $table->string('title')->nullable();
            $table->timestamps();
        });
    }

    protected function useSchema(string $schema): void
    {
        $declarations = \file_get_contents(__DIR__ . '/../../resources/graphql/idempotency.graphql');
        $this->schema = $declarations . "\n" . $schema;
        $this->setUpTestSchema();
    }
}
