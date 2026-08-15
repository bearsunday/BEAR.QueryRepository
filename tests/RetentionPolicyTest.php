<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Log\Context\CacheErrorContext;
use BEAR\QueryRepository\Log\Context\CacheHitContext;
use BEAR\QueryRepository\Log\Context\CacheMissContext;
use BEAR\QueryRepository\Log\Context\CommandContext;
use BEAR\QueryRepository\Log\Context\CommandResultContext;
use BEAR\QueryRepository\Log\Context\GetContext;
use BEAR\QueryRepository\Log\Context\InvalidateContext;
use BEAR\QueryRepository\Log\Context\ManualPurgeContext;
use BEAR\QueryRepository\Log\Context\ManualPurgeResultContext;
use BEAR\QueryRepository\Log\Context\PreWriteCleanupContext;
use BEAR\QueryRepository\Log\Context\PutSkippedContext;
use BEAR\QueryRepository\Log\Context\SaveEtagContext;
use BEAR\QueryRepository\Log\Context\SaveValueContext;
use BEAR\QueryRepository\Log\KeepMutationsAndFailures;
use BEAR\QueryRepository\Log\SafeSemanticLogger;
use Koriym\SemanticLogger\EventEntry;
use Koriym\SemanticLogger\LogJson;
use Koriym\SemanticLogger\SemanticLogger;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use PHPUnit\Framework\TestCase;

/**
 * What production keeps, and what it is right to throw away
 *
 * The measured content of a healthy read is zero: every entry says the cache did what it was
 * told. Keeping it would trade the whole benefit of recording for volume, so the policy has to
 * be exact about the line - especially the one case that looks like an invalidation and is not.
 */
class RetentionPolicyTest extends TestCase
{
    private KeepMutationsAndFailures $policy;
    private SemanticLoggerInterface $logger;

    protected function setUp(): void
    {
        $this->policy = new KeepMutationsAndFailures();
        $this->logger = new SafeSemanticLogger(new SemanticLogger());
    }

    public function testAHealthyReadIsDropped(): void
    {
        $open = $this->logger->open(new GetContext('page://self/html/blog-posting'));
        $this->logger->close(new CacheHitContext('view'), $open);

        $this->assertFalse($this->policy->keeps($this->logger->flush()));
    }

    public function testAColdReadIsDroppedEvenThoughItStores(): void
    {
        // The volume decision: after a deploy every request is this shape. Its tags and TTL are
        // reachable by sampling; keeping all of them would cost the most when load is highest.
        $open = $this->logger->open(new GetContext('page://self/html/blog-posting'));
        $this->logger->event(new PreWriteCleanupContext('page://self/html/blog-posting'));
        $this->logger->event($this->invalidate('purged'));
        $this->logger->event(new SaveValueContext('page://self/html/blog-posting', ['_tag_'], 60, true));
        $this->logger->event(new SaveEtagContext('page://self/html/blog-posting', '"e"', ['_tag_'], 60, true));
        $this->logger->close(new CacheMissContext('view'), $open);

        $log = $this->logger->flush();
        $this->assertFalse($this->policy->keeps($log), 'a marker-preceded invalidate is pre-write cleanup, not an invalidation');
    }

    public function testARealInvalidationIsKept(): void
    {
        // The same event without the marker: something was actually busted
        $this->logger->event($this->invalidate('purged'));

        $this->assertTrue($this->policy->keeps($this->logger->flush()));
    }

    public function testACommandIsKept(): void
    {
        $open = $this->logger->open(new CommandContext('onPut', [], 'CommandInterceptor'));
        $this->logger->close(new CommandResultContext(200), $open);

        $this->assertTrue($this->policy->keeps($this->logger->flush()));
    }

    public function testAManualPurgeIsKept(): void
    {
        $open = $this->logger->open(new ManualPurgeContext('page://self/html/comment'));
        $this->logger->close(new ManualPurgeResultContext(true), $open);

        $this->assertTrue($this->policy->keeps($this->logger->flush()));
    }

    public function testARefusedWriteIsKeptBecauseNothingElseRecordsIt(): void
    {
        // saved: false is the whole record - put()'s return value is discarded by the interceptor
        $open = $this->logger->open(new GetContext('page://self/html/blog-posting'));
        $this->logger->event(new SaveValueContext('page://self/html/blog-posting', ['_tag_'], 60, false));
        $this->logger->close(new CacheMissContext('view'), $open);

        $this->assertTrue($this->policy->keeps($this->logger->flush()));
    }

    public function testAFailedCdnPurgeIsKeptEvenWhenTheInvalidationWasOnlyCleanup(): void
    {
        // Marker-preceded, so the invalidation itself is not why this is kept: the CDN outcome is
        $open = $this->logger->open(new GetContext('page://self/html/blog-posting'));
        $this->logger->event(new PreWriteCleanupContext('page://self/html/blog-posting'));
        $this->logger->event($this->invalidate('failed'));
        $this->logger->close(new CacheMissContext('view'), $open);

        $this->assertTrue($this->policy->keeps($this->logger->flush()));
    }

    public function testAFailedPoolInvalidationIsKeptEvenWhenTheInvalidationWasOnlyCleanup(): void
    {
        $open = $this->logger->open(new GetContext('page://self/html/blog-posting'));
        $this->logger->event(new PreWriteCleanupContext('page://self/html/blog-posting'));
        $this->logger->event(new InvalidateContext(['_tag_'], false, true, 'skipped', 0.1));
        $this->logger->close(new CacheMissContext('view'), $open);

        $this->assertTrue($this->policy->keeps($this->logger->flush()));
    }

    public function testACacheOutageIsKept(): void
    {
        $open = $this->logger->open(new GetContext('page://self/html/blog-posting'));
        $this->logger->event(new CacheErrorContext('page://self/html/blog-posting', 'read', 'cache server down', 'RuntimeException'));
        $this->logger->close(new CacheMissContext('view'), $open);

        $this->assertTrue($this->policy->keeps($this->logger->flush()));
    }

    public function testAWriteSkippedByAnErrorCodeIsKeptAndOtherSkipReasonsAreNot(): void
    {
        $open = $this->logger->open(new GetContext('page://self/html/blog-posting'));
        $this->logger->event(new PutSkippedContext('page://self/html/blog-posting', 'error-code', 400));
        $this->logger->close(new CacheMissContext('view'), $open);
        $this->assertTrue($this->policy->keeps($this->logger->flush()));

        $open = $this->logger->open(new GetContext('page://self/html/blog-posting'));
        $this->logger->event(new PutSkippedContext('page://self/html/blog-posting', 'not-cacheable'));
        $this->logger->close(new CacheMissContext('view'), $open);
        $this->assertFalse($this->policy->keeps($this->logger->flush()), 'a resource that is simply not cacheable is not an incident');
    }

    public function testAFailedOutcomeIsFoundWhereTheRootKeepsItAsAList(): void
    {
        // `close` is one entry on a scope but a list at the root (LogJson::toArray() collects
        // orphan closes there), so a policy that assumes the scope shape reads nothing
        $close = new EventEntry('manual_store_result_1', 'manual_store_result', 'https://example.com/s.json', ['result' => 'failed']);

        $this->assertTrue($this->policy->keeps(new LogJson('', [], [$close])));
    }

    public function testSamplingKeepsAnOtherwiseDroppedSession(): void
    {
        $open = $this->logger->open(new GetContext('page://self/html/blog-posting'));
        $this->logger->close(new CacheHitContext('view'), $open);
        $log = $this->logger->flush();

        $this->assertFalse((new KeepMutationsAndFailures())->keeps($log));
        $this->assertTrue((new KeepMutationsAndFailures(1))->keeps($log), 'rate 1 keeps every session');
    }

    public function testAnEmptySessionIsDropped(): void
    {
        $this->assertFalse($this->policy->keeps(new LogJson('', [], [])));
    }

    /** @param 'failed'|'purged'|'skipped' $cdnStatus */
    private function invalidate(string $cdnStatus): InvalidateContext
    {
        return new InvalidateContext(['_tag_'], true, true, $cdnStatus, 0.1);
    }
}
