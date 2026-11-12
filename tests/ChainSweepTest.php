<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A hit answers as the miss did, whatever the chain of resources below the page looks like */
class ChainSweepTest extends TestCase
{
    /** @return iterable<string, array{'json'|'string', list<key-of<ChainSweep::TOPS>>}> renderer, the kinds of page */
    public static function renderers(): iterable
    {
        yield 'json' => ['json', ['value']];
        yield 'string' => ['string', ['value', 'response', 'donut']];
    }

    /**
     * @param 'json'|'string'                $renderer
     * @param list<key-of<ChainSweep::TOPS>> $tops
     */
    #[DataProvider('renderers')]
    public function testEveryChainAnswersAHitAsItsMiss(string $renderer, array $tops): void
    {
        $this->assertSame([], (new ChainSweep($renderer, $tops, __DIR__ . '/tmp/sweep'))->failures());
    }
}
