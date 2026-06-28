<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceInterface;
use FakeVendor\HelloWorld\Resource\App\Code;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

class EtagSetterTest extends TestCase
{
    private ResourceInterface $resource;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resource = (new Injector(ModuleFactory::getInstance('FakeVendor\HelloWorld'), __DIR__ . '/tmp'))->getInstance(ResourceInterface::class);
    }

    public function testStatusNotOk(): void
    {
        $setEtag = new EtagSetter(new CacheDependency(new UriTag(), new RepositoryLogger()));
        $ro = new Code();
        $ro->code = 500;
        $setEtag($ro);
        // $ro->headers[Header::ETAG]
        $this->assertArrayNotHasKey(Header::ETAG, $ro->headers);
    }

    public function testInvoke(): void
    {
        $ro = $this->resource->get('app://self/user', ['id' => 1]);
        $setEtag = new EtagSetter(new CacheDependency(new UriTag(), new RepositoryLogger()));
        $time = 0;
        $setEtag($ro, $time);
        $expect = 'Thu, 01 Jan 1970 00:00:00 +0000';
        $this->assertSame($expect, $ro->headers['Last-Modified']);
        $this->assertIsString($ro->headers[Header::ETAG]); // @phpstan-ignore-line
    }
}
