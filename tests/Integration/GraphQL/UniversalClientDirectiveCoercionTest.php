<?php

declare(strict_types = 1);

namespace Noldors\LighthouseIdempotency\Tests\Integration\GraphQL;

use PHPUnit\Framework\Attributes\TestWith;
use Noldors\LighthouseIdempotency\Tests\Support\GraphqlTestCase;

final class UniversalClientDirectiveCoercionTest extends GraphqlTestCase
{
    #[TestWith(['__typename'])]
    #[TestWith(['__schema { queryType { name } }'])]
    #[TestWith(['__type(name: "Query") { name }'])]
    public function testIntrospectionFieldsRejectAnExplicitNullKeyVariableWithAValidDefault(string $selection): void
    {
        $this->useSchema('type Query { noop: Boolean @mock }');
        $this->mockResolverExpects($this->never());
        $space = \mb_strpos($selection, ' {');
        $field = false === $space ? $selection : \mb_substr($selection, 0, $space);
        $children = false === $space ? '' : \mb_substr($selection, $space);
        $document = 'query($key: IdempotencyKey = "11111111-1111-1111-1111-111111111111") { ' . $field . ' @idempotent(key: $key)' . $children . ' }';
        $this->graphQL($document, ['key' => null])->assertGraphQLErrorMessage(
            'Argument "key" of non-null type "IdempotencyKey!" must not be null.',
        );
    }

    public function testPresentNullKeyIsRejectedEvenOnASkippedIntrospectionField(): void
    {
        $this->useSchema('type Query { noop: Boolean @mock }');
        $this->mockResolverExpects($this->never());
        $document = 'query($key: IdempotencyKey = "11111111-1111-1111-1111-111111111111") { __typename __type(name: "Query") @skip(if: true) @idempotent(key: $key) { name } }';
        $this->graphQL($document, ['key' => null])->assertGraphQLErrorMessage(
            'Argument "key" of non-null type "IdempotencyKey!" must not be null.',
        );
    }

    public function testOnlySelectedOperationAndItsReachableFragmentsCheckRuntimeNulls(): void
    {
        $this->useSchema('type Query { noop: Boolean @mock }');
        $this->mockResolverExpects($this->never());
        $document = 'query Selected { __typename } query Other($key: IdempotencyKey = "11111111-1111-1111-1111-111111111111") { ...OtherFields } fragment OtherFields on Query { __typename @idempotent(key: $key) }';
        $this->graphQL($document, ['key' => null], ['operationName' => 'Selected'])
            ->assertGraphQLErrorFree()
            ->assertJsonPath(
                'data.__typename',
                'Query',
            );
        $this->graphQL($document, ['key' => null], ['operationName' => 'Other'])->assertGraphQLErrorMessage(
            'Argument "key" of non-null type "IdempotencyKey!" must not be null.',
        );
    }

    public function testReachableNamedAndInlineFragmentsValidateTheCurrentInvocation(): void
    {
        $this->useSchema('type Query { noop: Boolean @mock }');
        $this->mockResolverExpects($this->never());
        $document = 'query($key: IdempotencyKey = "11111111-1111-1111-1111-111111111111") { ...Fields } fragment Fields on Query { ... on Query { __typename @idempotent(key: $key) } }';
        $this->graphQL($document)->assertGraphQLErrorFree()->assertJsonPath('data.__typename', 'Query');
        $this->graphQL($document, ['key' => null])->assertGraphQLErrorMessage(
            'Argument "key" of non-null type "IdempotencyKey!" must not be null.',
        );
    }
}
