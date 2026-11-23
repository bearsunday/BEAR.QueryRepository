<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use PHPUnit\Framework\TestCase;

class EntityTagsTest extends TestCase
{
    public function testEachEntityTagOfTheListIsReturnedWithoutItsWeakIndicatorAndQuotes(): void
    {
        $this->assertSame(['927897379', 'a/b+c=', 'x:y'], (new EntityTags())->of('"927897379", W/"a/b+c=", "x:y"'));
    }

    public function testACommaInsideQuotesDoesNotSplitTheTag(): void
    {
        $this->assertSame(['a,b', 'c'], (new EntityTags())->of('"a,b" , "c"'));
    }

    public function testAFieldValueThatIsNotAListOfEntityTagsYieldsNone(): void
    {
        $this->assertSame([], (new EntityTags())->of('"abc" "def"'));
        $this->assertSame([], (new EntityTags())->of("\"abc\"\n"));
        $this->assertSame([], (new EntityTags())->of('""'));
    }

    public function testServerIssuedGrammarsPass(): void
    {
        // crc32 decimal (production setters) and the sanitized URI tag (dev setter).
        $this->assertSame(['927897379'], (new EntityTags())->of('"927897379"'));
        $this->assertSame(['_user_id=1'], (new EntityTags())->of('"_user_id=1"'));
    }
}
