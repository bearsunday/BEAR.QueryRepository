<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\UnstorableResponseDetected;
use BEAR\Resource\Uri;
use FakeVendor\HelloWorld\Resource\App\HttpCacheControlWithCacheable;
use FakeVendor\HelloWorld\Resource\Page\None;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class UnstorableGateTest extends TestCase
{
    public function testUnmarkedResponseWithNoCacheControlIsStorable(): void
    {
        $gate = new UnstorableGate(new UncacheableMark(), new DeclaredCacheControl());
        $ro = new None();

        $this->assertNull($gate->reason($ro));
    }

    public function testMarkedResponseIsUnstorableRegardlessOfHeaders(): void
    {
        $mark = new UncacheableMark();
        $gate = new UnstorableGate($mark, new DeclaredCacheControl());
        $ro = new None();

        $mark->mark('csrf-token-issued');

        $this->assertSame('unstorable', $gate->reason($ro));
    }

    public function testCacheControlNoStoreIsUnstorable(): void
    {
        $gate = new UnstorableGate(new UncacheableMark(), new DeclaredCacheControl());
        $ro = new None();
        $ro->headers[Header::CACHE_CONTROL] = 'no-store';

        $this->assertSame('unstorable', $gate->reason($ro));
    }

    public function testCacheControlPrivateIsUnstorable(): void
    {
        $gate = new UnstorableGate(new UncacheableMark(), new DeclaredCacheControl());
        $ro = new None();
        $ro->headers[Header::CACHE_CONTROL] = 'PRIVATE';

        $this->assertSame('unstorable', $gate->reason($ro), 'RFC 9111 directives are case-insensitive');
    }

    public function testCacheControlHeaderNameIsMatchedCaseInsensitively(): void
    {
        // $ro->headers is a plain PHP array, not an HTTP-aware collection - 'cache-control'
        // must be found the same way 'Cache-Control' is (RFC 7230 §3.2).
        $gate = new UnstorableGate(new UncacheableMark(), new DeclaredCacheControl());
        $ro = new None();
        $ro->headers['cache-control'] = 'no-store';

        $this->assertSame('unstorable', $gate->reason($ro));
    }

    public function testCacheControlMatchingTheDeclaredValueIsExempt(): void
    {
        // #[HttpCache(isPrivate: true)]'s own value is the attribute speaking, not the
        // method - #[Cacheable] still stores server-side (issue #222).
        $gate = new UnstorableGate(new UncacheableMark(), new DeclaredCacheControl());
        $ro = new HttpCacheControlWithCacheable();
        $ro->headers[Header::CACHE_CONTROL] = 'private';

        $this->assertNull($gate->reason($ro));
    }

    public function testCacheControlDivergingFromTheDeclaredValueIsNotExempt(): void
    {
        // A method or render that adds its own no-store on top of (or instead of) the
        // declared value still trips the gate.
        $gate = new UnstorableGate(new UncacheableMark(), new DeclaredCacheControl());
        $ro = new HttpCacheControlWithCacheable();
        $ro->headers[Header::CACHE_CONTROL] = 'private, no-store';

        $this->assertSame('unstorable', $gate->reason($ro));
    }

    public function testOrdinaryCacheControlIsStorable(): void
    {
        $gate = new UnstorableGate(new UncacheableMark(), new DeclaredCacheControl());
        $ro = new None();
        $ro->headers[Header::CACHE_CONTROL] = 'max-age=60';

        $this->assertNull($gate->reason($ro));
    }

    public function testReasonExcludingHoleMarksIgnoresAMarkMadeInsideAHoleScope(): void
    {
        $mark = new UncacheableMark();
        $gate = new UnstorableGate($mark, new DeclaredCacheControl());
        $ro = new None();

        $mark->enterHole('page://self/html/some-hole');
        $mark->mark('csrf-token-issued');
        $mark->leaveHole();

        $this->assertNull($gate->reasonExcludingHoleMarks($ro), 'a hole-scoped mark must not trip the #[DonutCache] template gate');
        $this->assertSame('unstorable', $gate->reason($ro), 'the same mark still trips the gate for paths whose stored content includes hole output');
    }

    public function testReasonExcludingHoleMarksStillCatchesAMarkMadeOutsideAnyHole(): void
    {
        $mark = new UncacheableMark();
        $gate = new UnstorableGate($mark, new DeclaredCacheControl());
        $ro = new None();

        $mark->mark('csrf-token-issued');

        $this->assertSame('unstorable', $gate->reasonExcludingHoleMarks($ro));
    }

    public function testReasonExcludingHoleMarksCatchesANestedHolesOwnMarkForItself(): void
    {
        // A hole that is itself a #[DonutCache] resource runs its own write while still
        // nested inside its embedder's enterHole() scope - its own mark, made while it is
        // the current (innermost) scope, must still refuse its OWN template.
        $mark = new UncacheableMark();
        $gate = new UnstorableGate($mark, new DeclaredCacheControl());
        $ro = new None();

        $mark->enterHole('page://self/html/outer-hole');
        $mark->mark('csrf-token-issued'); // the nested hole's own render-time mark
        $this->assertSame('unstorable', $gate->reasonExcludingHoleMarks($ro), 'the hole\'s own mark, made while it is the current scope, must refuse its own template');
        $mark->leaveHole();

        $this->assertNull($gate->reasonExcludingHoleMarks($ro), 'back at the top level, the hole\'s mark (owned by the hole) must not refuse the composing page\'s own template');
    }

    public function testUnbalancedLeaveHoleDoesNotFailOpen(): void
    {
        // A caller that leaves a hole scope it never entered must not drive the stack
        // negative and then silently stop attributing later marks to the page.
        $mark = new UncacheableMark();
        $mark->leaveHole();

        $mark->mark('csrf-token-issued');

        $this->assertTrue($mark->isMarkedForCurrentScope());
    }

    public function testStackStaysBalancedWhenAHoleScopeIsLeftAfterAnException(): void
    {
        // DonutRequest::__toString() wraps a hole's own execution in try/finally so
        // leaveHole() still runs when the hole throws - simulated here directly on the
        // mark service. A page mark made afterward must read as the current (top-level)
        // scope, not as still being inside the unwound hole's scope.
        $mark = new UncacheableMark();
        $mark->enterHole('page://self/html/throwing-hole');
        try {
            throw new RuntimeException('the hole blew up mid-render');
        } catch (RuntimeException) {
            // swallowed here only to simulate try/finally running leaveHole() on the way out
        } finally {
            $mark->leaveHole();
        }

        $mark->mark('csrf-token-issued');

        $this->assertTrue($mark->isMarkedForCurrentScope());
    }

    public function testThrowingGateThrowsWhenUnstorable(): void
    {
        $mark = new UncacheableMark();
        $gate = new ThrowingUnstorableGate(new UnstorableGate($mark, new DeclaredCacheControl()));
        $ro = new None();
        $ro->uri = new Uri('app://self/unstorable-value');
        $mark->mark('csrf-token-issued');

        $this->expectException(UnstorableResponseDetected::class);
        $this->expectExceptionMessage('app://self/unstorable-value is unstorable for this request (unstorable)');
        $gate->reason($ro);
    }

    public function testThrowingGateReturnsNullWhenStorable(): void
    {
        $gate = new ThrowingUnstorableGate(new UnstorableGate(new UncacheableMark(), new DeclaredCacheControl()));
        $ro = new None();

        $this->assertNull($gate->reason($ro));
    }

    public function testMarkIsStickyOnceSet(): void
    {
        $mark = new UncacheableMark();
        $this->assertFalse($mark->isMarked());

        $mark->mark('first');
        $this->assertTrue($mark->isMarked());

        // Later calls cannot unmark a request; the service carries no reset.
        $mark->mark('second');
        $this->assertTrue($mark->isMarked());
    }
}
