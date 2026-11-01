<?php

declare(strict_types = 1);

namespace Noldors\LighthouseIdempotency\Tests\Unit;

use GraphQL\Error\Error;
use GraphQL\Language\Parser;
use PHPUnit\Framework\Attributes\TestWith;
use Noldors\LighthouseIdempotency\Tests\TestCase;
use Noldors\LighthouseIdempotency\Scalars\IdempotencyKey;

final class IdempotencyKeyTest extends TestCase
{
    public function testUuidInputIsNormalizedWithoutVersionRestriction(): void
    {
        $scalar = new IdempotencyKey(['name' => 'IdempotencyKey']);
        $this->assertSame(
            'a1311d71-3b87-4ce2-b0f6-c930c63b2205',
            $scalar->parseValue('A1311D71-3B87-4CE2-B0F6-C930C63B2205'),
        );
        $this->assertSame(
            '018f0f1e-7f19-7a30-b4ef-0aa0ab0a1234',
            $scalar->parseLiteral(
                Parser::parseValue('"018f0f1e-7f19-7a30-b4ef-0aa0ab0a1234"'),
            ),
        );
    }

    #[TestWith(['invalid'])]
    #[TestWith([123])]
    #[TestWith([null])]
    public function testInvalidValuesFailCoercion(mixed $value): void
    {
        $scalar = new IdempotencyKey(['name' => 'IdempotencyKey']);
        $this->expectException(Error::class);
        $scalar->parseValue($value);
    }
}
