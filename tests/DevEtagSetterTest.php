<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceInterface;
use FakeVendor\HelloWorld\Resource\App\Code;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

class DevEtagSetterTest extends TestCase
{
    private ResourceInterface $resource;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resource = (new Injector(ModuleFactory::getInstance('FakeVendor\HelloWorld'), __DIR__ . '/tmp'))->getInstance(ResourceInterface::class);
    }

    public function testInvoke(): void
    {
        $ro = $this->resource->get('app://self/user', ['id' => 1]);
        (new DevEtagSetter())($ro);

        $this->assertSame('"' . (new UriTag())($ro->uri) . '"', $ro->headers[Header::ETAG]);
    }

    public function testStatusNotOk(): void
    {
        // The URI-based validator is a debugging aid, but a validator on a non-200 still
        // lets ConditionalResponse::isModified() answer 304 in place of that response.
        // The fake answers 203, one of the statuses a donut stores.
        $ro = new Code();
        (new DevEtagSetter())($ro);

        $this->assertArrayNotHasKey(Header::ETAG, $ro->headers);
    }
}
