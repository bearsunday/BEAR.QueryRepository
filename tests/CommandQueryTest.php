<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;
use PHPUnit\Framework\TestCase;
use stdClass;

final class CommandQueryTest extends TestCase
{
    public function testBaseQueryUsesParameterOrderAndDropsDefaultedAndExtraKeys(): void
    {
        $ro = $this->resource();
        $query = ['extra' => 'x', 'page' => 2, 'token' => 't', 'userId' => 'camel', 'user_id' => 'snake'];

        $this->assertSame(['userId' => 'camel', 'token' => 't'], CommandQuery::baseQuery($ro, $query));
        $this->assertSame(['extra' => 'x', 'page' => 2, 'token' => 't', 'userId' => 'camel', 'user_id' => 'snake'], $query);
    }

    public function testBaseQueryOmitsMissingAndNullValuesWithoutSnakeFallback(): void
    {
        $ro = $this->resource();

        $this->assertSame([], CommandQuery::baseQuery($ro, []));
        $this->assertSame([], CommandQuery::baseQuery($ro, ['user_id' => 'snake', 'token' => null]));
        $this->assertSame([], CommandQuery::baseQuery($ro, ['userId' => null, 'user_id' => 'snake']));
    }

    public function testBaseQueryPreservesValuesWithoutConvertingThem(): void
    {
        $ro = $this->resource();
        $object = new stdClass();
        $query = ['userId' => ['nested' => [1, null]], 'token' => $object];

        $this->assertSame($query, CommandQuery::baseQuery($ro, $query));
        $this->assertSame(['userId' => 0, 'token' => false], CommandQuery::baseQuery($ro, ['userId' => 0, 'token' => false]));
        $this->assertSame(['userId' => '', 'token' => []], CommandQuery::baseQuery($ro, ['userId' => '', 'token' => []]));
    }

    public function testBaseQueryKeepsSuppliedVariadicParameter(): void
    {
        $ro = new class extends ResourceObject {
            public function onGet(mixed ...$items): static
            {
                unset($items);

                return $this;
            }
        };

        $this->assertSame(['items' => [1, 2]], CommandQuery::baseQuery($ro, ['items' => [1, 2], 'extra' => 3]));
        $this->assertSame([], CommandQuery::baseQuery($ro, []));
    }

    public function testBaseQueryWithoutParametersIsEmpty(): void
    {
        $ro = new class extends ResourceObject {
            public function onGet(): static
            {
                return $this;
            }
        };

        $this->assertSame([], CommandQuery::baseQuery($ro, ['extra' => 'x']));
    }

    public function testSnakeQueryConvertsAcronymsAndPreservesValuesAndOrder(): void
    {
        $object = new stdClass();
        $query = ['userId' => ['nestedKey' => 1], 'URLValue' => $object, 'token' => null];

        $this->assertSame(['user_id' => ['nestedKey' => 1], 'u_r_l_value' => $object, 'token' => null], CommandQuery::snakeQuery($query));
        $this->assertSame(['userId' => ['nestedKey' => 1], 'URLValue' => $object, 'token' => null], $query);
        $this->assertSame([], CommandQuery::snakeQuery([]));
    }

    public function testSnakeQueryKeepsTheLastValueWhenNamesCollide(): void
    {
        $this->assertSame(['user_id' => 'snake'], CommandQuery::snakeQuery(['userId' => 'camel', 'user_id' => 'snake']));
        $this->assertSame(['user_id' => 'camel'], CommandQuery::snakeQuery(['user_id' => 'snake', 'userId' => 'camel']));
    }

    private function resource(): ResourceObject
    {
        return new class extends ResourceObject {
            public function onGet(mixed $userId, mixed $token, int $page = 1): static
            {
                unset($userId, $token, $page);

                return $this;
            }
        };
    }
}
