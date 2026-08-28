<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\Resource\ResourceInterface;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

use function is_float;
use function is_int;
use function json_decode;

/**
 * What the log says a cache answer cost
 *
 * Every other flow asks whether the cache is correct. This one asks whether it is worth having:
 * a hit close measures serving from the pool, a miss close measures the resource run and the
 * write it triggered. The assertions are about shape and sign, never about a number - the value
 * moves with the machine.
 */
class HitCostLogTest extends TestCase
{
    use SemanticLogTreeTrait;

    private ResourceInterface $resource;
    private SemanticLoggerInterface $logger;

    protected function setUp(): void
    {
        $injector = new Injector(new FakeEtagPoolModule(ModuleFactory::getInstance('FakeVendor\HelloWorld')), __DIR__ . '/tmp');
        $this->resource = $injector->getInstance(ResourceInterface::class);
        $this->logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

        parent::setUp();
    }

    /** @return array{miss: float, hit: float} */
    private function costOfBothAnswers(): array
    {
        $this->resource->get('app://self/user', ['id' => 1]);
        $miss = $this->durationOf(self::closeContextJsonOf($this->flushAndValidate($this->logger), 'cache_miss'));

        $this->resource->get('app://self/user', ['id' => 1]);
        $hit = $this->durationOf(self::closeContextJsonOf($this->flushAndValidate($this->logger), 'cache_hit'));

        return ['miss' => $miss, 'hit' => $hit];
    }

    private function durationOf(string|null $contextJson): float
    {
        $this->assertNotNull($contextJson, 'the close carries no context');
        /** @var array<string, mixed> $context */
        $context = (array) json_decode((string) $contextJson, true);
        $this->assertArrayHasKey('durationMs', $context, (string) $contextJson);
        /** @var mixed $duration */
        $duration = $context['durationMs'] ?? null;
        // The log goes through json_encode without JSON_PRESERVE_ZERO_FRACTION, so a
        // whole-valued duration (1.000 ms) decodes as int. Number, not float.
        $this->assertTrue(is_int($duration) || is_float($duration), 'a close measures a scope, so it always has a duration');

        return (float) $duration;
    }

    public function testBothAnswersReportWhatTheyCost(): void
    {
        $cost = $this->costOfBothAnswers();

        $this->assertGreaterThan(0, $cost['miss'], 'running the resource takes time');
        $this->assertGreaterThanOrEqual(0, $cost['hit']);
    }

    public function testTheHitIsTheCheaperAnswer(): void
    {
        // The invariant is the sign, not the size: a hit that is not faster than a miss is a cache
        // costing money for nothing. The fixture's miss does real work, so this is not a coin flip.
        $cost = $this->costOfBothAnswers();

        $this->assertLessThan($cost['miss'], $cost['hit'], 'a hit that is not cheaper than a miss buys nothing');
    }

    public function testAnInnerLookupCarriesNoDuration(): void
    {
        // `layer: donut` is an event, not a close: there is no scope around it to measure, and a
        // zero would read as "instant".
        $this->resource->get('page://self/html/blog-posting?id=0');
        $json = self::eventContextJsonOf($this->flushAndValidate($this->logger), 'cache_miss');

        $this->assertNotNull($json, 'the donut lookup was not recorded, so nothing is asserted');
        $this->assertStringContainsString('"layer":"donut"', (string) $json);
        $this->assertStringContainsString('"durationMs":null', (string) $json, 'an event has no scope to measure');
    }
}
