<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use PHPUnit\Framework\TestCase;

/**
 * Which tokens are worth looking up
 *
 * The parser reduces an If-None-Match field value to pool-key candidates. A token this server
 * could never have issued - PSR-6 reserved characters, or the RFC-special `*` - is dropped:
 * it can never match, and handing it to the pool would turn a request header into a thrown
 * InvalidArgumentException (a client-chosen 500).
 */
class EntityTagsTest extends TestCase
{
    public function testATokenWithPsr6ReservedCharactersIsDropped(): void
    {
        // Symfony throws InvalidArgumentException for keys containing {}()/\@: - a validator
        // carrying one is unanswerable, so it is dropped before the pool sees it.
        $this->assertSame([], EntityTags::of('"x:y"'));
        $this->assertSame([], EntityTags::of('"a/b"'));
        $this->assertSame([], EntityTags::of('W/"a@b"'));
        $this->assertSame([], EntityTags::of('x{y'));
    }

    public function testAValidTokenBesideADroppedOneStillMatches(): void
    {
        // Dropping is per-token, not per-field: a list with one honest validator revalidates.
        $this->assertSame(['927897379'], EntityTags::of('"927897379", "x:y"'));
    }

    public function testStarIsNotAnOpaqueTag(): void
    {
        // RFC 9110 §13.1.2 gives `*` existence semantics; it is not a validator to look up.
        $this->assertSame([], EntityTags::of('*'));
        $this->assertSame(['abc'], EntityTags::of('"abc", *'));
    }

    public function testServerIssuedGrammarsPass(): void
    {
        // crc32 decimal (production setters) and the sanitized URI tag (dev setter).
        $this->assertSame(['927897379'], EntityTags::of('"927897379"'));
        $this->assertSame(['_user_id=1'], EntityTags::of('"_user_id=1"'));
    }
}
