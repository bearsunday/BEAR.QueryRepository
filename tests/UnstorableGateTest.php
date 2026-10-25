<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

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
        $mark->leaveHole('page://self/html/some-hole');

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
        $mark->leaveHole('page://self/html/outer-hole');

        $this->assertNull($gate->reasonExcludingHoleMarks($ro), 'back at the top level, the hole\'s mark (owned by the hole) must not refuse the composing page\'s own template');
    }

    public function testUnbalancedLeaveHoleDoesNotFailOpen(): void
    {
        // A caller that leaves a hole scope it never entered must not drive the stack
        // negative and then silently stop attributing later marks to the page.
        $mark = new UncacheableMark();
        $mark->leaveHole('page://self/html/never-entered');

        $mark->mark('csrf-token-issued');

        $this->assertTrue($mark->isMarkedForCurrentScope());
    }

    public function testAMismatchedLeaveHoleDoesNotPopAnActiveOuterScope(): void
    {
        // A stray leaveHole() call a caller makes without a matching enterHole() of its
        // own, while nested inside a REAL, still-open outer scope, must not pop that outer
        // scope out from under it - or a later mark in the outer scope's remaining
        // execution is wrongly attributed to the page instead, reading as not marked where
        // the outer resource's own write check expects it to (under-refusal).
        $mark = new UncacheableMark();
        $mark->enterHole('page://self/html/outer-hole');
        $mark->leaveHole('page://self/html/some-other-uri-never-entered');

        $mark->mark('csrf-token-issued');

        // Properly leave the SAME scope now: if the mismatched call above had already
        // (wrongly) popped it, this is a no-op and the mark - now sitting at top level -
        // would still read as current here; if the mismatched call was correctly ignored,
        // this pop succeeds and takes the mark (attributed to 'outer-hole', not top level)
        // down with it, leaving top level unmarked.
        $mark->leaveHole('page://self/html/outer-hole');

        $this->assertFalse($mark->isMarkedForCurrentScope(), 'the mark must have been attributed to the outer scope, not leaked to top level by the earlier mismatched call');
    }

    public function testLeaveHoleSelfHealsALeakedInnerScope(): void
    {
        // The mirror failure of the mismatch case above: if code nested inside an
        // enterHole()/leaveHole() pair pushes its OWN scope and never leaves it (a leak -
        // third-party misuse again), the outer leaveHole() must still succeed, taking the
        // leaked entry down with it - a strict "only pop if it is the exact top" check
        // would instead get permanently stuck behind the leak for the rest of the request.
        $mark = new UncacheableMark();
        $mark->enterHole('page://self/html/outer-hole');
        $mark->enterHole('page://self/html/leaked-inner-hole'); // never left - a leak

        $mark->leaveHole('page://self/html/outer-hole');
        $mark->mark('csrf-token-issued');

        // Re-enter the LEAKED uri specifically: if the stack had not actually been
        // unwound, the mark above landed there (still the current top) and this reads
        // true; if it was correctly unwound, the mark landed at top level instead and
        // this reads false.
        $mark->enterHole('page://self/html/leaked-inner-hole');
        $isLeakedScopeMarked = $mark->isMarkedForCurrentScope();
        $mark->leaveHole('page://self/html/leaked-inner-hole');

        $this->assertFalse($isLeakedScopeMarked, 'the leaked inner scope must not have captured a mark made after the outer scope was correctly (unwound) closed');
    }

    public function testStackStaysBalancedWhenAHoleScopeIsLeftAfterAnException(): void
    {
        // DonutRequest::__toString() wraps a hole's own execution in try/finally so
        // leaveHole() still runs when the hole throws - simulated here directly on the
        // mark service. The hole's own mark, made before it "throws", must not leak into
        // the current (top-level) scope once that scope is correctly left - checking a
        // fresh mark() made only afterward would be tautological: it and the immediately
        // following isMarkedForCurrentScope() always resolve through the same
        // currentOwner(), so they would agree regardless of whether the stack is balanced.
        $mark = new UncacheableMark();
        $mark->enterHole('page://self/html/throwing-hole');
        $mark->mark('csrf-token-issued'); // the hole's own mark, before it "throws"
        try {
            throw new RuntimeException('the hole blew up mid-render');
        } catch (RuntimeException) {
            // swallowed here only to simulate try/finally running leaveHole() on the way out
        } finally {
            $mark->leaveHole('page://self/html/throwing-hole');
        }

        $this->assertFalse($mark->isMarkedForCurrentScope(), 'the hole\'s own mark must not leak into the current (top-level) scope once its scope is left');
    }

    public function testReasonForOwnWriteCatchesACompoundLeakMarkedDuringMyOwnWriteWindow(): void
    {
        // #234 rejected option: rescoping doPut() to isMarkedForCurrentScope() ONLY leaks a
        // #[Cacheable] page P embedding an independently donut-composed hole H that marks -
        // H's mark is recorded under H's own owner, never P's, so P's scope check alone never
        // trips. wasMarkedSinceCurrentWrite() is what catches it: H marked DURING P's own open
        // write window.
        $mark = new UncacheableMark();
        $gate = new UnstorableGate($mark, new DeclaredCacheControl());
        $ro = new None();
        $ro->uri = new Uri('page://self/html/p');

        $mark->enterWrite($ro); // P's own write window opens
        $mark->enterHole('page://self/html/nested-donut-hole'); // H's own scope
        $mark->mark('csrf-token-issued'); // H marks itself, owned by H, not P
        $mark->leaveHole('page://self/html/nested-donut-hole');

        $this->assertSame('unstorable', $gate->reasonForOwnWrite($ro), 'a mark during my own write window refuses MY write even though it belongs to a different owner');
        $mark->leaveWrite();
    }

    public function testReasonForOwnWriteIgnoresASiblingThatMarkedAndClosedItsOwnWriteBeforeMineOpened(): void
    {
        // The literal #234 repro: an unrelated sibling marks inside its OWN write window,
        // which closes before the next (unrelated) write's own window opens.
        // wasMarkedSinceCurrentWrite() alone would correctly ignore this; so must the OR'd
        // isMarkedForCurrentScope() - neither half may over-refuse this.
        $mark = new UncacheableMark();
        $gate = new UnstorableGate($mark, new DeclaredCacheControl());
        $ro = new None();
        $ro->uri = new Uri('app://self/mine');

        $mark->enterWrite(new None());
        $mark->enterHole('page://self/html/marking-hole');
        $mark->mark('csrf-token-issued');
        $mark->leaveHole('page://self/html/marking-hole');
        $mark->leaveWrite();

        $mark->enterWrite($ro);
        $this->assertNull($gate->reasonForOwnWrite($ro), 'an unrelated sibling marked and closed BEFORE my own write window opened must not refuse mine');
        $mark->leaveWrite();
    }

    public function testReasonForOwnWriteStillRefusesAPreExistingMarkInTheSameScope(): void
    {
        // #234 rejected option: a pure counter/snapshot-only predicate misses a mark made
        // BEFORE the resource tree (and so this write's own enterWrite()) even started -
        // breaking testRefreshSkipsSavingContentAndCdnHeaderWhenMarkedUnstorable. The OR'd
        // isMarkedForCurrentScope() is what still catches it here: the pre-existing mark
        // shares this write's own (TOP_LEVEL) scope.
        $mark = new UncacheableMark();
        $gate = new UnstorableGate($mark, new DeclaredCacheControl());
        $ro = new None();
        $ro->uri = new Uri('app://self/mine');

        $mark->mark('pre-existing');
        $mark->enterWrite($ro); // this write's own window opens AFTER the mark

        $this->assertFalse($mark->wasMarkedSinceCurrentWrite(), 'sanity: nothing marked SINCE this write opened');
        $this->assertSame('unstorable', $gate->reasonForOwnWrite($ro), 'a pre-existing mark sharing this write\'s own scope must still refuse it');
        $mark->leaveWrite();
    }

    public function testReasonForOwnWriteCatchesADescendantCalledSynchronouslyAfterAnAncestorHolesOwnMark(): void
    {
        // #234 rejected option: wasMarkedSinceCurrentWrite() ALONE misses this - a
        // #[Cacheable] resource called from inside a marking hole's own execution, right
        // after its mark(), opens its own write window AFTER the mark already fired, so the
        // counter alone reads "nothing new". isMarkedForCurrentScope() is what catches it:
        // the descendant shares the still-open marking ancestor's own scope.
        $mark = new UncacheableMark();
        $gate = new UnstorableGate($mark, new DeclaredCacheControl());
        $ro = new None();
        $ro->uri = new Uri('app://self/mine');

        $mark->enterHole('page://self/html/marking-hole');
        $mark->mark('csrf-token-issued');
        $mark->enterWrite($ro); // the descendant's own write window, opened AFTER the mark

        $this->assertFalse($mark->wasMarkedSinceCurrentWrite(), 'sanity: nothing marked SINCE this write opened');
        $this->assertSame('unstorable', $gate->reasonForOwnWrite($ro), 'a descendant sharing the still-open marking ancestor\'s scope must be refused');
        $mark->leaveWrite();
        $mark->leaveHole('page://self/html/marking-hole');
    }

    public function testReasonForOwnWriteIsStorableWithNoMarkAtAll(): void
    {
        $gate = new UnstorableGate(new UncacheableMark(), new DeclaredCacheControl());
        $ro = new None();

        $this->assertNull($gate->reasonForOwnWrite($ro));
    }

    public function testReasonForOwnWriteFallsBackToAnyOwnerWhenTheOpenFrameBelongsToADifferentInstanceSharingTheSameUri(): void
    {
        // Object identity, not URI equality (Kimi/Fable review): a different INSTANCE at the
        // same URI as the one enterWrite() opened the frame with must not be treated as
        // owning it. A mark made and closed in an UNRELATED hole scope BEFORE the write
        // opens is invisible to both halves of the scoped predicate
        // (isMarkedForCurrentScope(): never shared TOP_LEVEL; wasMarkedSinceCurrentWrite():
        // already ticked before enterWrite() snapshotted it) - storable for the TRUE frame
        // owner. A uri-string match would wrongly trust that same scoped predicate for the
        // different instance too, also reading storable; identity correctly falls back to
        // any-owner isMarked() instead, which IS true, refusing it - the two instances must
        // disagree, proving identity (not uri) is what is actually being compared.
        $mark = new UncacheableMark();
        $gate = new UnstorableGate($mark, new DeclaredCacheControl());

        $mark->enterHole('page://self/html/unrelated-hole');
        $mark->mark('csrf-token-issued'); // marked and closed BEFORE any write opens
        $mark->leaveHole('page://self/html/unrelated-hole');

        $frameOwner = new None();
        $frameOwner->uri = new Uri('app://self/mine');
        $differentInstanceSameUri = new None();
        $differentInstanceSameUri->uri = new Uri('app://self/mine');

        $mark->enterWrite($frameOwner);

        $this->assertNull($gate->reasonForOwnWrite($frameOwner), 'the TRUE frame owner: the pre-existing mark belongs to a different, already-closed scope - neither scoped half catches it');
        $this->assertSame('unstorable', $gate->reasonForOwnWrite($differentInstanceSameUri), 'a DIFFERENT instance at the same uri does not own this frame - any-owner isMarked() (true) refuses it instead, disagreeing with the frame owner\'s own answer above');
        $mark->leaveWrite();
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
