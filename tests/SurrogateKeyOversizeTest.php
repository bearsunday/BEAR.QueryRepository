<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\Resource\ResourceInterface;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Madapaja\TwigModule\TwigModule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

use function array_map;
use function dirname;
use function explode;
use function json_decode;
use function max;
use function strlen;

use const JSON_THROW_ON_ERROR;

class SurrogateKeyOversizeTest extends TestCase
{
    use SemanticLogTreeTrait;

    private ResourceInterface $resource;
    private SemanticLoggerInterface $logger;

    protected function setUp(): void
    {
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance('FakeVendor\HelloWorld'));
        $module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));
        $injector = new Injector($module, __DIR__ . '/tmp');
        $this->resource = $injector->getInstance(ResourceInterface::class);
        $this->logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);
        $this->logger->flush();

        parent::setUp();
    }

    /** @return array<string, array{string, int, int}> */
    public static function oversizeProvider(): array
    {
        return [
            'value: one key over 1,024 bytes' => ['app://self/advertised-keys', 1025, 1],
            'value: header over 16,384 bytes' => ['app://self/advertised-keys', 565, 29],
            'view: one key over 1,024 bytes' => ['app://self/advertised-keys-view', 1025, 1],
            'view: header over 16,384 bytes' => ['app://self/advertised-keys-view', 565, 29],
            'donut: one key over 1,024 bytes' => ['page://self/html/advertised-keys-donut', 1025, 1],
            'donut: header over 16,384 bytes' => ['page://self/html/advertised-keys-donut', 565, 29],
        ];
    }

    #[DataProvider('oversizeProvider')]
    public function testASurrogateKeyOverTheFastlyLimitIsLogged(string $uri, int $keyBytes, int $keys): void
    {
        $ro = $this->resource->get($uri, ['keyBytes' => $keyBytes, 'keys' => $keys]);
        $tree = $this->flushAndValidate($this->logger);

        $header = $ro->headers[Header::SURROGATE_KEY];
        $contexts = self::eventContextsJsonOf($tree, 'surrogate_key_oversize');
        $this->assertCount(1, $contexts);
        $this->assertSame([
            'uri' => (string) $ro->uri,
            'headerBytes' => strlen($header),
            'longestKeyBytes' => max(array_map('strlen', explode(' ', $header))),
        ], json_decode($contexts[0], true, 512, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, array{string, int, int}> */
    public static function withinLimitProvider(): array
    {
        return [
            'value: a 1,024-byte key' => ['app://self/advertised-keys', 1024, 1],
            'value: a 16,384-byte header' => ['app://self/advertised-keys', 564, 29],
            'view: a 1,024-byte key' => ['app://self/advertised-keys-view', 1024, 1],
            'view: a 16,384-byte header' => ['app://self/advertised-keys-view', 564, 29],
            'donut: a 1,024-byte key' => ['page://self/html/advertised-keys-donut', 1024, 1],
            'donut: a header under 16,384 bytes' => ['page://self/html/advertised-keys-donut', 500, 29],
        ];
    }

    #[DataProvider('withinLimitProvider')]
    public function testASurrogateKeyWithinTheFastlyLimitIsNotLogged(string $uri, int $keyBytes, int $keys): void
    {
        $ro = $this->resource->get($uri, ['keyBytes' => $keyBytes, 'keys' => $keys]);
        $tree = $this->flushAndValidate($this->logger);

        $header = $ro->headers[Header::SURROGATE_KEY];
        $this->assertLessThanOrEqual(16384, strlen($header));
        $this->assertLessThanOrEqual(1024, max(array_map('strlen', explode(' ', $header))));
        $this->assertSame([], self::eventContextsJsonOf($tree, 'surrogate_key_oversize'));
    }
}
