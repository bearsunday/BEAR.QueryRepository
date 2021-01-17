<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\Module\ResourceModule;
use BEAR\Resource\ResourceInterface;
use FakeVendor\HelloWorld\Resource\App\Code;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

class EtagSetterTest extends TestCase
{
    /** @var ResourceInterface */
    private $resource;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resource = (new Injector(new QueryRepositoryModule(new ResourceModule('FakeVendor\HelloWorld')), $_ENV['TMP_DIR']))->getInstance(ResourceInterface::class);
    }

    public function testStatusNotOk()
    {
        $setEtag = new EtagSetter();
        $ro = new Code;
        $ro->code = 500;
        $result = $setEtag($ro);
        $this->assertNull($result);
    }

    public function testInvoke(): void
    {
        $ro = $this->resource->get('app://self/user', ['id' => 1]);
        $setEtag = new EtagSetter();
        $time = 0;
        $setEtag($ro, $time);
        $expect = 'Thu, 01 Jan 1970 00:00:00 GMT';
        $this->assertSame($expect, $ro->headers['Last-Modified']);
        $this->assertIsString($ro->headers['ETag']);
    }
}
