<?php

declare(strict_types = 1);

namespace Noldors\LighthouseIdempotency\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 */
final class Record extends Model
{
    protected $table = 'fixture_records';

    protected $guarded = [];
}
