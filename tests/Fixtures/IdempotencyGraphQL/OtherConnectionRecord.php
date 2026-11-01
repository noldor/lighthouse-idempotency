<?php

declare(strict_types = 1);

namespace Noldors\LighthouseIdempotency\Tests\Fixtures\IdempotencyGraphQL;

use Illuminate\Database\Eloquent\Model;

final class OtherConnectionRecord extends Model
{
    protected $connection = 'unconfigured';

    protected $table = 'fixture_records';
}
