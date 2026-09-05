<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Log\Context\CacheErrorContext;
use BEAR\QueryRepository\Log\Context\CacheHitContext;
use BEAR\QueryRepository\Log\Context\CacheMissContext;
use BEAR\QueryRepository\Log\Context\ConditionalRequestContext;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\Uri;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;

use function assert;
use function restore_error_handler;
use function set_error_handler;

use const E_USER_WARNING;

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

    public function testIsNotModifiedForUnderstandsTheCliArgvForm(): void
    {
        $injector = new Injector(new FakeEtagPoolModule(ModuleFactory::getInstance('FakeVendor\HelloWorld')), __DIR__ . '/tmp');
        $storage = $injector->getInstance(ResourceStorageInterface::class);
        $storage->saveEtag(new Uri('page://self/x'), '"abc"', '', null);
        $httpCache = new CliHttpCache($storage);
        $server = [
            'argc' => 4,
            'argv' => ['bootstrap', 'get', 'page://self/x', 'If-None-Match="abc"'],
        ];

        $this->assertTrue($httpCache->isNotModifiedFor(new Uri('page://self/x'), $server), 'the CLI argv form carries the same validator isNotModified() reads');
        $this->assertFalse($httpCache->isNotModifiedFor(new Uri('page://self/y'), $server), 'another resource\'s live validator is not an answer about this one');
        $this->assertTrue($httpCache->isNotModifiedFor(new Uri('page://self/x'), ['HTTP_IF_NONE_MATCH' => '"abc"']), 'the HTTP form still matches');
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

    public function testAnUnreadableEtagPoolAnswersTheRequestInFull(): void
    {
        // The ETag pool is down: a validator that cannot be read is a validator that does not
        // match, so the request is answered in full rather than failed (issue #190). The scope
        // still closes - an unclosed one would surface as an unclosed_at_flush diagnostic - and
        // records the outage as the established idiom reads it: cache_error + cache_miss.
        $storage = ResourceStorageTest::getResourceStorageInstance(etagPool: new TagAwareAdapter(new FakeErrorCache()));

        foreach ([HttpCache::class, CliHttpCache::class] as $class) {
            $logger = new RecordingSemanticLogger();
            $httpCache = new $class($storage, $logger);

            $warned = false;
            set_error_handler(static function (int $errno) use (&$warned): bool {
                $warned = $warned || $errno === E_USER_WARNING;

                return true;
            });
            try {
                $notModified = $httpCache->isNotModified(['HTTP_IF_NONE_MATCH' => '"any"', 'REQUEST_URI' => '/user?id=1']);
            } finally {
                restore_error_handler();
            }

            $this->assertFalse($notModified, $class . ': an unreadable validator cannot match');
            $this->assertTrue($warned, $class . ': the outage reaches the warning channel');
            $this->assertCount(1, $logger->opens, $class);
            $this->assertCount(1, $logger->closes, $class . ': the scope is closed');
        }
    }

    public function testTheScopedAnswerIsRecordedForBothBoundaries(): void
    {
        $resource = (new Injector(ModuleFactory::getInstance('FakeVendor\HelloWorld')))->getInstance(ResourceInterface::class);
        $user = $resource->get('app://self/user', ['id' => 1]);
        $other = $resource->get('app://self/user', ['id' => 2]);
        $storage = ResourceStorageTest::getResourceStorageInstance();
        $storage->saveEtag($user->uri, $user->headers[Header::ETAG], '', 10);
        $storage->saveEtag($other->uri, $other->headers[Header::ETAG], '', 10);

        // The scoped question, asked of both transfer boundaries: this validator, for this
        // resource. Another resource's live validator is not an answer about this one.
        foreach ([HttpCache::class, CliHttpCache::class] as $class) {
            $logger = new RecordingSemanticLogger();
            $httpCache = new $class($storage, $logger);
            $this->assertTrue($httpCache->isNotModifiedFor($user->uri, ['HTTP_IF_NONE_MATCH' => $user->headers[Header::ETAG]]), $class);
            $this->assertFalse($httpCache->isNotModifiedFor($user->uri, ['HTTP_IF_NONE_MATCH' => $other->headers[Header::ETAG]]), $class);
            $this->assertFalse($httpCache->isNotModifiedFor($user->uri, []), $class . ': no validator presents nothing');

            $this->assertCount(2, $logger->opens, $class . ': the header-less request records nothing');
            $hit = $logger->closes[0];
            assert($hit instanceof CacheHitContext);
            $this->assertSame('etag', $hit->layer, $class);
            $this->assertIsFloat($hit->durationMs, $class . ': a close measures its scope');
        }
    }

    public function testAStorageThatCannotScopeAnswersTheOlderQuestion(): void
    {
        // An application's own ResourceStorageInterface implementation predates the capability.
        // Falling back keeps its 304s working rather than answering 200 to every revalidation.
        $resource = (new Injector(ModuleFactory::getInstance('FakeVendor\HelloWorld')))->getInstance(ResourceInterface::class);
        $user = $resource->get('app://self/user', ['id' => 1]);
        $etag = (string) $user->headers[Header::ETAG];
        $unscopable = new FakeUnscopableStorage(ResourceStorageTest::getResourceStorageInstance());
        $unscopable->saveEtag($user->uri, $etag, '', 10);

        foreach ([HttpCache::class, CliHttpCache::class] as $class) {
            $httpCache = new $class($unscopable, new RecordingSemanticLogger());
            $this->assertTrue($httpCache->isNotModifiedFor($user->uri, ['HTTP_IF_NONE_MATCH' => $etag]), $class);
        }
    }

    public function testTheScopedAnswerDegradesWhenTheLookupThrows(): void
    {
        // The same decision as the unscoped answer, recorded against the URI that was asked for.
        $storage = ResourceStorageTest::getResourceStorageInstance(etagPool: new TagAwareAdapter(new FakeErrorCache()));
        $uri = new Uri('app://self/user?id=1');

        foreach ([HttpCache::class, CliHttpCache::class] as $class) {
            $logger = new RecordingSemanticLogger();
            $httpCache = new $class($storage, $logger);

            $warned = false;
            set_error_handler(static function (int $errno) use (&$warned): bool {
                $warned = $warned || $errno === E_USER_WARNING;

                return true;
            });
            try {
                $notModified = $httpCache->isNotModifiedFor($uri, ['HTTP_IF_NONE_MATCH' => '"any"']);
            } finally {
                restore_error_handler();
            }

            $this->assertFalse($notModified, $class . ': an unreadable validator cannot match');
            $this->assertTrue($warned, $class . ': the outage reaches the warning channel');
            $this->assertCount(1, $logger->closes, $class . ': the scope is closed');
            $error = $logger->events[0];
            assert($error instanceof CacheErrorContext);
            $this->assertSame('read', $error->operation, $class);
            $this->assertSame('app://self/user?id=1', $error->uri, $class);
        }
    }

    public function testAClientChosenUnpoolableTokenIsAMissNotAnError(): void
    {
        // Before EntityTags dropped these, `If-None-Match: "x:y"` reached the pool as a key,
        // Symfony threw InvalidArgumentException, and any client could turn a request into a 500
        // that logged like a pool outage. Unanswerable tokens are a plain miss at both boundaries.
        $storage = ResourceStorageTest::getResourceStorageInstance();
        $uri = new Uri('app://self/user?id=1');

        foreach ([HttpCache::class, CliHttpCache::class] as $class) {
            $logger = new RecordingSemanticLogger();
            $httpCache = new $class($storage, $logger);

            $this->assertFalse($httpCache->isNotModified(['HTTP_IF_NONE_MATCH' => '"x:y"']), $class);
            $this->assertFalse($httpCache->isNotModifiedFor($uri, ['HTTP_IF_NONE_MATCH' => '"a/b, c"']), $class);
            $this->assertFalse($httpCache->isNotModified(['HTTP_IF_NONE_MATCH' => '*']), $class . ': * is existence semantics, not a key');

            $this->assertCount(0, $logger->events, $class . ': no cache_error - the pool was never asked');
            $this->assertCount(3, $logger->closes, $class . ': each decision closes as an ordinary miss');
        }
    }
}
