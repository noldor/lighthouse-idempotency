<?php

declare(strict_types = 1);

namespace Noldors\LighthouseIdempotency\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

final class PrimaryReadRecord extends Model
{
    protected $connection = 'split-probe';

    protected $table = 'fixture_records';

    protected $guarded = [];
}
