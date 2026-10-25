<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

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

        $mark->enterWrite();
        $this->assertFalse($mark->wasMarkedSinceCurrentWrite());
        $mark->leaveWrite();
    }

    public function testWasMarkedSinceCurrentWriteIsTrueWhenMarkedDuringTheWindow(): void
    {
        $mark = new UncacheableMark();

        $mark->enterWrite();
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

        $mark->enterWrite(); // outer write opens
        $mark->enterWrite(); // inner (nested) write opens, same tick
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

        $mark->enterWrite();
        $mark->mark('csrf-token-issued');
        $mark->leaveWrite();

        // The stack is empty again: a later, unrelated write opening AFTER this one closed
        // must not see this mark via the write-scope predicate (only isMarkedForCurrentScope()
        // - a different mechanism - decides a shared-scope case).
        $mark->enterWrite();
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

    public function testEnterInterceptorLeaveInterceptorTracksDepthAndReportsOutermostOnly(): void
    {
        $mark = new UncacheableMark();

        $mark->enterInterceptor(); // outermost
        $mark->enterInterceptor(); // nested embed

        $this->assertFalse($mark->leaveInterceptor(), 'the nested one is not the outermost - depth is still 1');
        $this->assertTrue($mark->leaveInterceptor(), 'depth is back to zero - this was the outermost');
    }
}
