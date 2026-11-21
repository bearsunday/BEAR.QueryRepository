<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;
use Ray\Di\InjectorInterface;
use stdClass;

final class CommandQueryTest extends TestCase
{
    private InjectorInterface $injector;

    protected function setUp(): void
    {
        $this->injector = new Injector(ModuleFactory::getInstance('FakeVendor\HelloWorld'), __DIR__ . '/tmp');
    }

    public function testBaseQueryUsesParameterOrderAndDropsDefaultedAndExtraKeys(): void
    {
        $ro = $this->resource();
        $query = ['extra' => 'x', 'page' => 2, 'token' => 't', 'userId' => 'camel', 'user_id' => 'snake'];

        $this->assertSame(['userId' => 'camel', 'token' => 't'], $this->injector->getInstance(CommandQuery::class)->baseQuery($ro, $query));
        $this->assertSame(['extra' => 'x', 'page' => 2, 'token' => 't', 'userId' => 'camel', 'user_id' => 'snake'], $query);
    }

    public function testBaseQueryOmitsMissingAndNullValuesWithoutSnakeFallback(): void
    {
        $ro = $this->resource();

        $this->assertSame([], $this->injector->getInstance(CommandQuery::class)->baseQuery($ro, []));
        $this->assertSame([], $this->injector->getInstance(CommandQuery::class)->baseQuery($ro, ['user_id' => 'snake', 'token' => null]));
        $this->assertSame([], $this->injector->getInstance(CommandQuery::class)->baseQuery($ro, ['userId' => null, 'user_id' => 'snake']));
    }

    public function testBaseQueryPreservesValuesWithoutConvertingThem(): void
    {
        $ro = $this->resource();
        $object = new stdClass();
        $query = ['userId' => ['nested' => [1, null]], 'token' => $object];

        $this->assertSame($query, $this->injector->getInstance(CommandQuery::class)->baseQuery($ro, $query));
        $this->assertSame(['userId' => 0, 'token' => false], $this->injector->getInstance(CommandQuery::class)->baseQuery($ro, ['userId' => 0, 'token' => false]));
        $this->assertSame(['userId' => '', 'token' => []], $this->injector->getInstance(CommandQuery::class)->baseQuery($ro, ['userId' => '', 'token' => []]));
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

        $this->assertSame(['items' => [1, 2]], $this->injector->getInstance(CommandQuery::class)->baseQuery($ro, ['items' => [1, 2], 'extra' => 3]));
        $this->assertSame([], $this->injector->getInstance(CommandQuery::class)->baseQuery($ro, []));
    }

    public function testBaseQueryWithoutParametersIsEmpty(): void
    {
        $ro = new class extends ResourceObject {
            public function onGet(): static
            {
                return $this;
            }
        };

        $this->assertSame([], $this->injector->getInstance(CommandQuery::class)->baseQuery($ro, ['extra' => 'x']));
    }

    public function testSnakeQueryConvertsAcronymsAndPreservesValuesAndOrder(): void
    {
        $object = new stdClass();
        $query = ['userId' => ['nestedKey' => 1], 'URLValue' => $object, 'token' => null];

        $this->assertSame(['user_id' => ['nestedKey' => 1], 'u_r_l_value' => $object, 'token' => null], $this->injector->getInstance(CommandQuery::class)->snakeQuery($query));
        $this->assertSame(['userId' => ['nestedKey' => 1], 'URLValue' => $object, 'token' => null], $query);
        $this->assertSame([], $this->injector->getInstance(CommandQuery::class)->snakeQuery([]));
    }

    public function testSnakeQueryKeepsTheLastValueWhenNamesCollide(): void
    {
        $this->assertSame(['user_id' => 'snake'], $this->injector->getInstance(CommandQuery::class)->snakeQuery(['userId' => 'camel', 'user_id' => 'snake']));
        $this->assertSame(['user_id' => 'camel'], $this->injector->getInstance(CommandQuery::class)->snakeQuery(['user_id' => 'snake', 'userId' => 'camel']));
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
