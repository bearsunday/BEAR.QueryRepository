<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\UnmatchedQuery;
use BEAR\Resource\ResourceObject;
use BEAR\Resource\Uri;
use PHPUnit\Framework\TestCase;

class MatchQueryTest extends TestCase
{
    private MatchQuery $matchQuery;
    private ResourceObject $ro;

    protected function setUp(): void
    {
        $this->matchQuery = new MatchQuery();
        $this->ro = new class extends ResourceObject {
            public function onGet(int $id, int $page = 1): static
            {
                return $this;
            }
        };

        parent::setUp();
    }

    public function testOmitsMissingParameterWithDefaultValue(): void
    {
        $this->ro->uri = new Uri('app://self/x', ['id' => 1]);

        $this->assertSame(['id' => 1], ($this->matchQuery)($this->ro));
    }

    public function testKeepsParameterExplicitlyGiven(): void
    {
        $this->ro->uri = new Uri('app://self/x', ['id' => 1, 'page' => 2]);

        $this->assertSame(['id' => 1, 'page' => 2], ($this->matchQuery)($this->ro));
    }

    public function testThrowsForMissingRequiredParameter(): void
    {
        $this->ro->uri = new Uri('app://self/x');

        $this->expectException(UnmatchedQuery::class);
        ($this->matchQuery)($this->ro);
    }
}
