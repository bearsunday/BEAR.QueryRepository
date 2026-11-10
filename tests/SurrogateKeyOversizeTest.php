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

    /**
     * The donut page appends its own URI tag and group tag, both carrying the query, so it sends more than it advertises.
     *
     * @return array<string, array{string, int, int, int}>
     */
    public static function oversizeProvider(): array
    {
        return [
            'value: a 1,025-byte key' => ['app://self/advertised-keys', 1025, 1025, 1025],
            'value: a 16,385-byte header' => ['app://self/advertised-keys', 1000, 16385, 16385],
            'view: a 1,025-byte key' => ['app://self/advertised-keys-view', 1025, 1025, 1025],
            'view: a 16,385-byte header' => ['app://self/advertised-keys-view', 1000, 16385, 16385],
            'donut: a 1,025-byte key' => ['page://self/html/advertised-keys-donut', 1025, 1025, 1147],
            'donut: a 16,385-byte header' => ['page://self/html/advertised-keys-donut', 1000, 16261, 16385],
        ];
    }

    #[DataProvider('oversizeProvider')]
    public function testASurrogateKeyOverTheFastlyLimitIsLogged(string $uri, int $keyBytes, int $headerBytes, int $sentBytes): void
    {
        $ro = $this->resource->get($uri, ['keyBytes' => $keyBytes, 'headerBytes' => $headerBytes]);
        $tree = $this->flushAndValidate($this->logger);

        $header = $ro->headers[Header::SURROGATE_KEY];
        $this->assertSame($sentBytes, strlen($header));
        $contexts = self::eventContextsJsonOf($tree, 'surrogate_key_oversize');
        $this->assertCount(1, $contexts);
        $this->assertSame([
            'uri' => (string) $ro->uri,
            'headerBytes' => $sentBytes,
            'longestKeyBytes' => max(array_map('strlen', explode(' ', $header))),
        ], json_decode($contexts[0], true, 512, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, array{string, int, int, int}> */
    public static function withinLimitProvider(): array
    {
        return [
            'value: a 1,024-byte key' => ['app://self/advertised-keys', 1024, 1024, 1024],
            'value: a 16,384-byte header' => ['app://self/advertised-keys', 1000, 16384, 16384],
            'view: a 1,024-byte key' => ['app://self/advertised-keys-view', 1024, 1024, 1024],
            'view: a 16,384-byte header' => ['app://self/advertised-keys-view', 1000, 16384, 16384],
            'donut: a 1,024-byte key' => ['page://self/html/advertised-keys-donut', 1024, 1024, 1146],
            'donut: a 16,384-byte header' => ['page://self/html/advertised-keys-donut', 1000, 16260, 16384],
        ];
    }

    #[DataProvider('withinLimitProvider')]
    public function testASurrogateKeyWithinTheFastlyLimitIsNotLogged(string $uri, int $keyBytes, int $headerBytes, int $sentBytes): void
    {
        $ro = $this->resource->get($uri, ['keyBytes' => $keyBytes, 'headerBytes' => $headerBytes]);
        $tree = $this->flushAndValidate($this->logger);

        $header = $ro->headers[Header::SURROGATE_KEY];
        $this->assertSame($sentBytes, strlen($header));
        $this->assertLessThanOrEqual(1024, max(array_map('strlen', explode(' ', $header))));
        $this->assertSame([], self::eventContextsJsonOf($tree, 'surrogate_key_oversize'));
    }
}
