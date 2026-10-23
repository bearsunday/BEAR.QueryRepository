<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\UnstorableResponseDetected;
use BEAR\Resource\Uri;
use FakeVendor\HelloWorld\Resource\Page\None;
use PHPUnit\Framework\TestCase;

class UnstorableGateTest extends TestCase
{
    public function testUnmarkedResponseWithNoCacheControlIsStorable(): void
    {
        $gate = new UnstorableGate(new UncacheableMark());
        $ro = new None();

        $this->assertNull($gate->reason($ro));
    }

    public function testMarkedResponseIsUnstorableRegardlessOfHeaders(): void
    {
        $mark = new UncacheableMark();
        $gate = new UnstorableGate($mark);
        $ro = new None();

        $mark->mark('csrf-token-issued');

        $this->assertSame('unstorable', $gate->reason($ro));
    }

    public function testCacheControlNoStoreIsUnstorable(): void
    {
        $gate = new UnstorableGate(new UncacheableMark());
        $ro = new None();
        $ro->headers[Header::CACHE_CONTROL] = 'no-store';

        $this->assertSame('unstorable', $gate->reason($ro));
    }

    public function testCacheControlPrivateIsUnstorable(): void
    {
        $gate = new UnstorableGate(new UncacheableMark());
        $ro = new None();
        $ro->headers[Header::CACHE_CONTROL] = 'private';

        $this->assertSame('unstorable', $gate->reason($ro));
    }

    public function testCacheControlMatchingTheDeclaredValueIsExempt(): void
    {
        // #[HttpCache(isPrivate: true)]'s own value is the attribute speaking, not the
        // method - #[Cacheable] still stores server-side (issue #222).
        $gate = new UnstorableGate(new UncacheableMark());
        $ro = new None();
        $ro->headers[Header::CACHE_CONTROL] = 'private';

        $this->assertNull($gate->reason($ro, 'private'));
    }

    public function testCacheControlDivergingFromTheDeclaredValueIsNotExempt(): void
    {
        // A method or render that adds its own no-store on top of (or instead of) the
        // declared value still trips the gate.
        $gate = new UnstorableGate(new UncacheableMark());
        $ro = new None();
        $ro->headers[Header::CACHE_CONTROL] = 'private, no-store';

        $this->assertSame('unstorable', $gate->reason($ro, 'private'));
    }

    public function testOrdinaryCacheControlIsStorable(): void
    {
        $gate = new UnstorableGate(new UncacheableMark());
        $ro = new None();
        $ro->headers[Header::CACHE_CONTROL] = 'max-age=60';

        $this->assertNull($gate->reason($ro));
    }

    public function testThrowingGateThrowsWhenUnstorable(): void
    {
        $mark = new UncacheableMark();
        $gate = new ThrowingUnstorableGate(new UnstorableGate($mark));
        $ro = new None();
        $ro->uri = new Uri('app://self/unstorable-value');
        $mark->mark('csrf-token-issued');

        $this->expectException(UnstorableResponseDetected::class);
        $this->expectExceptionMessage('app://self/unstorable-value is unstorable for this request (unstorable)');
        $gate->reason($ro);
    }

    public function testThrowingGateReturnsNullWhenStorable(): void
    {
        $gate = new ThrowingUnstorableGate(new UnstorableGate(new UncacheableMark()));
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
