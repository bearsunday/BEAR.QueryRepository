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

    public function testTheHitSkipsTheWorkTheMissDoes(): void
    {
        // "Cheaper" is not a number, it is a shape: a miss runs the resource and writes the
        // pool, each step its own point under the scope; a hit is one pool read with nothing
        // inside it. That asymmetry is what a cache buys, and unlike two wall-clock readings it
        // does not depend on how busy the machine happens to be at the moment of the assertion.
        $this->resource->get('app://self/user', ['id' => 1]);
        $missEvents = self::scopeEventTypesOf($this->flushAndValidate($this->logger), 'get', 'app://self/user?id=1');

        $this->resource->get('app://self/user', ['id' => 1]);
        $hitEvents = self::scopeEventTypesOf($this->flushAndValidate($this->logger), 'get', 'app://self/user?id=1');

        $this->assertNotEmpty($missEvents, 'a miss runs the resource and writes the pool; each step is recorded as a point');
        $this->assertSame([], $hitEvents, 'a hit is a single pool read; nothing happens inside its scope');
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
