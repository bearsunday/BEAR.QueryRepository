<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Log\Context\CacheHitContext;
use BEAR\QueryRepository\Log\Context\CacheMissContext;
use BEAR\QueryRepository\Log\Context\ConditionalRequestContext;
use BEAR\Resource\ResourceInterface;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

use function assert;

class HttpCacheTest extends TestCase
{
    public function testisNotModifiedFale(): CliHttpCache
    {
        $storage = ResourceStorageTest::getResourceStorageInstance();
        $httpCache = new CliHttpCache($storage);
        $server = [];
        $this->assertFalse($httpCache->isNotModified($server));

        return $httpCache;
    }

    public function testisNotModifiedTrue(): CliHttpCache
    {
        $resource = (new Injector(ModuleFactory::getInstance('FakeVendor\HelloWorld')))->getInstance(ResourceInterface::class);
        $user = $resource->get('app://self/user', ['id' => 1]);
        $storage = ResourceStorageTest::getResourceStorageInstance();
        $storage->saveEtag($user->uri, $user->headers[Header::ETAG], '', 10);
        $httpCache = new CliHttpCache($storage);
        $server = ['HTTP_IF_NONE_MATCH' => $user->headers[Header::ETAG]];
        $this->assertTrue($httpCache->isNotModified($server));

        return $httpCache;
    }

    /** @depends testisNotModifiedTrue */
    public function testCliHttpCacheTransfer(CliHttpCache $httpCache): void
    {
        $this->expectOutputRegex('/\A304 Not Modified/');
        $httpCache->transfer();
    }

    /** @depends testisNotModifiedTrue */
    public function testHeaderSetInCli(): void
    {
        $resource = (new Injector(ModuleFactory::getInstance('FakeVendor\HelloWorld')))->getInstance(ResourceInterface::class);
        $user = $resource->get('app://self/user', ['id' => 1]);
        $storage = ResourceStorageTest::getResourceStorageInstance();
        $storage->saveEtag($user->uri, $user->headers[Header::ETAG], '', 10);
        $httpCache = new CliHttpCache($storage);
        $header = 'IF_NONE_MATCH=' . $user->headers[Header::ETAG];
        $server = [
            'argc' => 4,
            'argv' => [3 => $header],
        ];
        $this->assertTrue($httpCache->isNotModified($server));
    }

    public function testConditionalRequestAnswerIsRecorded(): void
    {
        $resource = (new Injector(ModuleFactory::getInstance('FakeVendor\HelloWorld')))->getInstance(ResourceInterface::class);
        $user = $resource->get('app://self/user', ['id' => 1]);
        $etag = $user->headers[Header::ETAG];
        $storage = ResourceStorageTest::getResourceStorageInstance();
        $storage->saveEtag($user->uri, $etag, '', 10);

        // Both transfer-boundary implementations record the same scope: the validator
        // presented, closed with the pool's answer at layer "etag". A hit is the 304
        // decision - the whole request served without running the resource - which no
        // get scope can ever show.
        foreach ([HttpCache::class, CliHttpCache::class] as $class) {
            $logger = new RecordingSemanticLogger();
            $httpCache = new $class($storage, $logger);

            $this->assertTrue($httpCache->isNotModified(['HTTP_IF_NONE_MATCH' => $etag]), $class);
            $this->assertFalse($httpCache->isNotModified(['HTTP_IF_NONE_MATCH' => '"stale"']), $class);
            $this->assertFalse($httpCache->isNotModified([]), $class);

            $this->assertCount(2, $logger->opens, $class . ': no validator presents nothing and records nothing');
            $open = $logger->opens[0];
            assert($open instanceof ConditionalRequestContext);
            $this->assertSame($etag, $open->ifNoneMatch, $class);
            $hit = $logger->closes[0];
            assert($hit instanceof CacheHitContext);
            $this->assertSame('etag', $hit->layer, $class);
            $miss = $logger->closes[1];
            assert($miss instanceof CacheMissContext);
            $this->assertSame('etag', $miss->layer, $class);
        }
    }
}
