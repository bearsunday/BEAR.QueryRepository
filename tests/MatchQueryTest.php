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

    public function testReadsSnakeCaseKeyUnderParameterName(): void
    {
        $ro = new class extends ResourceObject {
            public function onGet(int $userId): static
            {
                return $this;
            }
        };
        $ro->uri = new Uri('app://self/x', ['user_id' => 1]);

        $this->assertSame(['userId' => 1], ($this->matchQuery)($ro));
    }

    public function testPrefersTheParameterNameWhenBothSpellingsAreGiven(): void
    {
        $ro = new class extends ResourceObject {
            public function onGet(int $userId): static
            {
                return $this;
            }
        };
        $ro->uri = new Uri('app://self/x', ['user_id' => 2, 'userId' => 1]);

        $this->assertSame(['userId' => 1], ($this->matchQuery)($ro));
    }

    public function testReadsSnakeCaseKeyOfParameterWithDefaultValue(): void
    {
        $ro = new class extends ResourceObject {
            public function onGet(int $userId, int $perPage = 10): static
            {
                return $this;
            }
        };
        $ro->uri = new Uri('app://self/x', ['user_id' => 1, 'per_page' => 20]);

        $this->assertSame(['userId' => 1, 'perPage' => 20], ($this->matchQuery)($ro));
    }

    /** BEAR.Resource hands the write null here, so the snake_case value does not address the entry it wrote. */
    public function testNullUnderTheParameterNameIsNotReplacedBySnakeCaseKey(): void
    {
        $ro = new class extends ResourceObject {
            public function onGet(int|null $userId): static
            {
                return $this;
            }
        };
        $ro->uri = new Uri('app://self/x', ['userId' => null, 'user_id' => 1]);

        $this->expectException(UnmatchedQuery::class);
        ($this->matchQuery)($ro);
    }

    /** BEAR.Resource skips a null under the name of a default-valued parameter and reads the snake_case key. */
    public function testNullUnderTheNameOfParameterWithDefaultValueFallsBackToSnakeCaseKey(): void
    {
        $ro = new class extends ResourceObject {
            public function onGet(int $userId, int $perPage = 10): static
            {
                return $this;
            }
        };
        $ro->uri = new Uri('app://self/x', ['userId' => 1, 'perPage' => null, 'per_page' => 20]);

        $this->assertSame(['userId' => 1, 'perPage' => 20], ($this->matchQuery)($ro));
    }
}
