<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use PHPUnit\Framework\TestCase;

class TreeRendererTest extends TestCase
{
    private TreeRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new TreeRenderer();

        parent::setUp();
    }

    public function testRendersChainAsNestedTree(): void
    {
        $logs = [
            ['op' => 'depends-on', 'parent' => 'page://self/dep/level-two', 'child' => 'page://self/dep/level-three', 'childTags' => ['_dep_level-three_']],
            ['op' => 'depends-on', 'parent' => 'page://self/dep/level-one', 'child' => 'page://self/dep/level-two', 'childTags' => ['_dep_level-two_']],
        ];

        $expected = <<<'TREE'
            page://self/dep/level-one
            └── page://self/dep/level-two
                └── page://self/dep/level-three
            TREE;

        $this->assertSame($expected, $this->renderer->render($logs));
    }

    public function testRendersMultipleRootsSharingAChild(): void
    {
        $logs = [
            ['op' => 'depends-on', 'parent' => 'page://self/dep/parent-a', 'child' => 'page://self/dep/child-c', 'childTags' => ['_dep_child-c_']],
            ['op' => 'depends-on', 'parent' => 'page://self/dep/parent-b', 'child' => 'page://self/dep/child-c', 'childTags' => ['_dep_child-c_']],
        ];

        $expected = <<<'TREE'
            page://self/dep/parent-a
            └── page://self/dep/child-c

            page://self/dep/parent-b
            └── page://self/dep/child-c
            TREE;

        $this->assertSame($expected, $this->renderer->render($logs));
    }

    public function testRendersSiblingsWithBranchConnectors(): void
    {
        $logs = [
            ['op' => 'depends-on', 'parent' => 'page://self/blog', 'child' => 'app://self/comment', 'childTags' => ['_comment_']],
            ['op' => 'depends-on', 'parent' => 'page://self/blog', 'child' => 'app://self/author', 'childTags' => ['_author_']],
        ];

        $expected = <<<'TREE'
            page://self/blog
            ├── app://self/comment
            └── app://self/author
            TREE;

        $this->assertSame($expected, $this->renderer->render($logs));
    }

    public function testDeduplicatesRepeatedDependsOnEdges(): void
    {
        $edge = ['op' => 'depends-on', 'parent' => 'page://self/a', 'child' => 'app://self/b', 'childTags' => ['_b_']];
        $logs = [$edge, $edge, $edge];

        $expected = <<<'TREE'
            page://self/a
            └── app://self/b
            TREE;

        $this->assertSame($expected, $this->renderer->render($logs));
    }

    public function testIgnoresNonDependencyEntries(): void
    {
        $logs = [
            ['op' => 'cache-miss', 'uri' => 'page://self/a', 'layer' => 'resource'],
            ['op' => 'save-value', 'uri' => 'page://self/a', 'tags' => ['_a_'], 'ttl' => 60],
        ];

        $this->assertSame('(no dependencies)', $this->renderer->render($logs));
    }

    public function testGuardsAgainstCycles(): void
    {
        $logs = [
            ['op' => 'depends-on', 'parent' => 'page://self/root', 'child' => 'page://self/a', 'childTags' => ['_a_']],
            ['op' => 'depends-on', 'parent' => 'page://self/a', 'child' => 'page://self/b', 'childTags' => ['_b_']],
            ['op' => 'depends-on', 'parent' => 'page://self/b', 'child' => 'page://self/a', 'childTags' => ['_a_']],
        ];

        // root -> a -> b -> a: the re-entry to a is marked (cycle) and not expanded further
        $expected = <<<'TREE'
            page://self/root
            └── page://self/a
                └── page://self/b
                    └── page://self/a  (cycle)
            TREE;

        $this->assertSame($expected, $this->renderer->render($logs));
    }
}
