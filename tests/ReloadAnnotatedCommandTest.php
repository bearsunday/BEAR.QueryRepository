<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceInterface;
use BEAR\Resource\Uri;
use FakeVendor\HelloWorld\Resource\App\User;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

use function assert;
use function is_array;

class ReloadAnnotatedCommandTest extends TestCase
{
    private ResourceInterface $resource;
    private QueryRepositoryInterface $repository;

    protected function setUp(): void
    {
        $injector = new Injector(ModuleFactory::getInstance('FakeVendor\HelloWorld'), __DIR__ . '/tmp');
        $this->resource = $injector->getInstance(ResourceInterface::class);
        $this->repository = $injector->getInstance(QueryRepositoryInterface::class);
        User::$data = User::DATA;

        parent::setUp();
    }

    public function testPatchRefreshesTheEntryWithTheWrittenData(): void
    {
        $this->resource->patch('app://self/user', ['id' => 1, 'name' => 'koriym']);

        $state = $this->repository->get(new Uri('app://self/user?id=1'));
        $this->assertInstanceOf(ResourceState::class, $state, 'the PATCH left the entry empty');
        assert(is_array($state->body));
        $this->assertSame('koriym', $state->body['name'], 'the refreshed entry does not hold the written data');
        $user = $this->resource->get('app://self/user', ['id' => 1]);
        $this->assertArrayHasKey('Last-Modified', $user->headers);
        $this->assertSame($state->body['time'], $user['time'], 'the GET was not served from the refreshed entry');
    }
}
