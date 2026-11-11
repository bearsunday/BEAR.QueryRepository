<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Log\Context\CacheErrorContext;
use BEAR\QueryRepository\Log\Context\CacheHitContext;
use BEAR\QueryRepository\Log\Context\CacheMissContext;
use BEAR\QueryRepository\Log\Context\ConditionalRequestContext;
use BEAR\Resource\Uri;
use FakeVendor\HelloWorld\Resource\Page\Index;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;

use function assert;
use function restore_error_handler;
use function set_error_handler;

use const E_USER_WARNING;

class ConditionalRequestTest extends TestCase
{
    private ResourceStorage $storage;
    private RecordingSemanticLogger $logger;
    private Uri $uri;

    protected function setUp(): void
    {
        $this->storage = ResourceStorageTest::getResourceStorageInstance(
            roPool: new TagAwareAdapter(new ArrayAdapter()),
            etagPool: new TagAwareAdapter(new ArrayAdapter()),
        );
        $this->logger = new RecordingSemanticLogger();
        $this->uri = new Uri('page://self/conditional');
        $ro = new Index();
        $ro->uri = $this->uri;
        $ro->body = [];
        $ro->headers[Header::ETAG] = '"live"';
        $this->storage->saveValue($ro, 10);
        $this->storage->saveEtag($this->uri, '"live"', '', 10);
    }

    public function testUnscopedAnswerPreservesThePresentedValidator(): void
    {
        $validator = '"stale", W/"live"';
        $this->assertTrue((new ConditionalRequest($this->storage, $this->logger))->isNotModified($validator, []));
        $this->assertFalse((new ConditionalRequest($this->storage, $this->logger))->isNotModified('*', []));
        $open = $this->logger->opens[0];
        assert($open instanceof ConditionalRequestContext);
        $this->assertSame($validator, $open->ifNoneMatch);
        $this->assertInstanceOf(CacheHitContext::class, $this->logger->closes[0]);
        $this->assertInstanceOf(CacheMissContext::class, $this->logger->closes[1]);
        $this->assertSame([], $this->logger->events);
    }

    public function testScopedAnswerRejectsAnotherResource(): void
    {
        $this->assertTrue((new ConditionalRequest($this->storage, $this->logger))->isNotModifiedFor($this->uri, 'W/"live"', []));
        $this->assertFalse((new ConditionalRequest($this->storage, $this->logger))->isNotModifiedFor(new Uri('page://self/other'), '"live"', []));
        $this->assertInstanceOf(CacheHitContext::class, $this->logger->closes[0]);
        $this->assertInstanceOf(CacheMissContext::class, $this->logger->closes[1]);
    }

    public function testUnscopableStorageFallsBackToTheUnscopedAnswer(): void
    {
        $this->assertTrue((new ConditionalRequest(new FakeUnscopableStorage($this->storage), $this->logger))->isNotModifiedFor(
            new Uri('page://self/other'),
            '"live"',
            [],
        ));
        $this->assertCount(1, $this->logger->opens);
        $this->assertCount(1, $this->logger->closes);
        $this->assertInstanceOf(CacheHitContext::class, $this->logger->closes[0]);
    }

    public function testFailureClosesBeforeWarningAndUsesTheSelectedUri(): void
    {
        $storage = ResourceStorageTest::getResourceStorageInstance(etagPool: new TagAwareAdapter(new FakeErrorCache()));
        foreach ([null, $this->uri] as $uri) {
            foreach ([$storage, new FakeUnscopableStorage($storage)] as $candidate) {
                $logger = new RecordingSemanticLogger();
                $warnings = [];
                set_error_handler(function (int $errno, string $message) use ($logger, &$warnings): bool {
                    $this->assertSame(E_USER_WARNING, $errno);
                    $this->assertCount(1, $logger->events);
                    $this->assertCount(1, $logger->closes);
                    $warnings[] = $message;

                    return true;
                });
                try {
                    $server = ['REQUEST_URI' => '/original'];
                    $hit = $uri === null
                        ? (new ConditionalRequest($candidate, $logger))->isNotModified('"live"', $server)
                        : (new ConditionalRequest($candidate, $logger))->isNotModifiedFor($uri, '"live"', $server);
                } finally {
                    restore_error_handler();
                }

                $this->assertFalse($hit);
                $this->assertCount(1, $warnings);
                $this->assertStringStartsWith('RuntimeException: cache server down in ', $warnings[0]);
                $error = $logger->events[0];
                assert($error instanceof CacheErrorContext);
                $this->assertSame($uri !== null && $candidate instanceof ScopedValidatorInterface ? (string) $uri : '/original', $error->uri);
                $this->assertSame('read', $error->operation);
                $miss = $logger->closes[0];
                assert($miss instanceof CacheMissContext);
                $this->assertSame('etag', $miss->layer);
            }
        }
    }
}
