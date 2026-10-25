<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use FakeVendor\HelloWorld\Resource\Page\None;
use PHPUnit\Framework\TestCase;

/**
 * Direct unit coverage for the #234/dev-gate mechanics UncacheableMark gained: the write-scope
 * stack (enterWrite()/leaveWrite()/wasMarkedSinceCurrentWrite()), isNested(), and the
 * cacheable-interceptor nesting depth (enterInterceptor()/leaveInterceptor()).
 *
 * @see UnstorableGateTest for the OR'd predicate (reasonForOwnWrite()) these back
 */
class UncacheableMarkTest extends TestCase
{
    public function testWasMarkedSinceCurrentWriteIsFalseWithNoOpenWrite(): void
    {
        $mark = new UncacheableMark();

        $this->assertFalse($mark->wasMarkedSinceCurrentWrite(), 'nothing to compare against - a direct, non-AOP put() call outside any interceptor');
    }

    public function testWasMarkedSinceCurrentWriteIsFalseWhenNothingMarkedDuringTheWindow(): void
    {
        $mark = new UncacheableMark();

        $mark->enterWrite(new None());
        $this->assertFalse($mark->wasMarkedSinceCurrentWrite());
        $mark->leaveWrite();
    }

    public function testWasMarkedSinceCurrentWriteIsTrueWhenMarkedDuringTheWindow(): void
    {
        $mark = new UncacheableMark();

        $mark->enterWrite(new None());
        $mark->mark('csrf-token-issued');
        $this->assertTrue($mark->wasMarkedSinceCurrentWrite());
        $mark->leaveWrite();
    }

    public function testWasMarkedSinceCurrentWriteUsesTheInnermostOpenWritesOwnSnapshotWhenNested(): void
    {
        // A mark during a NESTED write's own window (e.g. an embedded hole's own write,
        // itself wrapped in enterWrite()/leaveWrite()) must also be visible to the OUTER
        // write still open around it - the compound-leak case (#234).
        $mark = new UncacheableMark();

        $mark->enterWrite(new None()); // outer write opens
        $mark->enterWrite(new None()); // inner (nested) write opens, same tick
        $this->assertFalse($mark->wasMarkedSinceCurrentWrite(), 'nothing marked yet');

        $mark->mark('csrf-token-issued');
        $this->assertTrue($mark->wasMarkedSinceCurrentWrite(), 'the inner write sees its own mark');
        $mark->leaveWrite(); // inner write closes

        $this->assertTrue($mark->wasMarkedSinceCurrentWrite(), 'the outer write still sees the mark made during the inner write it was open across');
        $mark->leaveWrite();
    }

    public function testLeaveWriteIsAPlainStackPop(): void
    {
        $mark = new UncacheableMark();

        $mark->enterWrite(new None());
        $mark->mark('csrf-token-issued');
        $mark->leaveWrite();

        // The stack is empty again: a later, unrelated write opening AFTER this one closed
        // must not see this mark via the write-scope predicate (only isMarkedForCurrentScope()
        // - a different mechanism - decides a shared-scope case).
        $mark->enterWrite(new None());
        $this->assertFalse($mark->wasMarkedSinceCurrentWrite());
        $mark->leaveWrite();
    }

    public function testIsNestedReflectsAnOpenHoleScope(): void
    {
        $mark = new UncacheableMark();

        $this->assertFalse($mark->isNested());

        $mark->enterHole('page://self/html/some-hole');
        $this->assertTrue($mark->isNested());

        $mark->leaveHole('page://self/html/some-hole');
        $this->assertFalse($mark->isNested());
    }

    public function testIsCurrentWriteForIsFalseWithNoOpenWrite(): void
    {
        $mark = new UncacheableMark();

        $this->assertFalse($mark->isCurrentWriteFor(new None()), 'a direct, non-AOP put() call outside any interceptor has no frame to match');
    }

    public function testIsCurrentWriteForMatchesByObjectIdentityNotUriEquality(): void
    {
        $owner = new None();
        $differentInstanceSameUri = new None();
        $differentInstanceSameUri->uri = $owner->uri;
        $mark = new UncacheableMark();

        $mark->enterWrite($owner);
        $this->assertTrue($mark->isCurrentWriteFor($owner), 'the exact instance enterWrite() opened the frame with');
        $this->assertFalse($mark->isCurrentWriteFor($differentInstanceSameUri), 'a DIFFERENT instance, even sharing the same uri, must not match - identity, not uri equality');
        $mark->leaveWrite();
    }

    public function testIsCurrentWriteForIsFalseWhenTheOpenFrameBelongsToADifferentInstance(): void
    {
        $mark = new UncacheableMark();

        $mark->enterWrite(new None());
        $this->assertFalse($mark->isCurrentWriteFor(new None()), 'a write nested inside a DIFFERENT resource\'s own frame does not own it');
        $mark->leaveWrite();
    }

    public function testSetWriteOwnerRebindsTheInnermostFrameWithoutTouchingItsTickSnapshot(): void
    {
        // CacheInterceptor's own re-bind: the frame must open BEFORE proceed() (a mark made
        // during proceed() must land inside the window), using the pre-proceed instance - but
        // proceed()'s return value, actually passed to put(), is not always that same
        // instance. setWriteOwner() updates WHICH instance the already-open frame belongs to.
        $preProceed = new None();
        $postProceed = new None();
        $mark = new UncacheableMark();

        $mark->enterWrite($preProceed);
        $mark->mark('csrf-token-issued'); // marked DURING proceed(), before the re-bind below
        $mark->setWriteOwner($postProceed);

        $this->assertFalse($mark->isCurrentWriteFor($preProceed), 'the pre-proceed instance no longer owns this frame');
        $this->assertTrue($mark->isCurrentWriteFor($postProceed), 'the frame now belongs to the instance actually being written');
        $this->assertTrue($mark->wasMarkedSinceCurrentWrite(), 'the tick snapshot taken at enterWrite() is untouched by the re-bind - the mark made during proceed() is still visible');
        $mark->leaveWrite();
    }

    public function testSetWriteOwnerIsANoOpWithNoOpenWrite(): void
    {
        $mark = new UncacheableMark();

        $mark->setWriteOwner(new None());
        $this->assertFalse($mark->isCurrentWriteFor(new None()), 'nothing to rebind - still no frame open, never throws');
    }

    public function testEnterInterceptorLeaveInterceptorTracksDepthAndReportsOutermostOnly(): void
    {
        $mark = new UncacheableMark();

        $mark->enterInterceptor(); // outermost
        $mark->enterInterceptor(); // nested embed

        $this->assertFalse($mark->leaveInterceptor(), 'the nested one is not the outermost - depth is still 1');
        $this->assertTrue($mark->leaveInterceptor(), 'depth is back to zero - this was the outermost');
    }
}
