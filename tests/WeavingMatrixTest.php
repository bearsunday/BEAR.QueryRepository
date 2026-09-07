<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

use function assert;
use function json_encode;
use function strtolower;
use function substr;
use function substr_count;

/**
 * Which interceptor is woven onto which method, for every cache declaration shape
 *
 * A declaration that does not weave is silent: the resource answers correctly and the suite stays
 * green. The pairs below are the ones an application can actually write, and each is asserted on
 * two observable facts - the write body ran, and the write announced an invalidation.
 */
class WeavingMatrixTest extends TestCase
{
    /**
     * Every shape an application can write, and whether its write must announce the change
     *
     * `MRNone` and `DCNone` carry the cache declaration on `onGet` only, so nothing names their
     * write: the binding matches the attribute *on* the method, and no matcher can express "a
     * class holding this attribute somewhere". Their writes run, and invalidating what they
     * changed needs `#[Purge]` or `#[Refresh]` written on the write.
     *
     * @return list<array{0: string, 1: string, 2: bool}>
     */
    public static function writeProvider(): array
    {
        $announces = [
            'CANone' => true,
            'CAPurge' => true,
            'CARefresh' => true,
            'CRNone' => true,
            'CRPurge' => true,
            'CRRefresh' => true,
            'MRNone' => false,
            'MRPurge' => true,
            'MRRefresh' => true,
            'DCNone' => false,
            'DCPurge' => true,
            'DCRefresh' => true,
        ];
        $cases = [];
        foreach ($announces as $shape => $announce) {
            foreach (['onPut', 'onPost', 'onDelete'] as $method) {
                $cases[] = [$shape, $method, $announce];
            }
        }

        return $cases;
    }

    /**
     * A write is never answered from the cache, and it announces what it changed
     *
     * @param bool $announces whether the shape carries a declaration that must produce a command scope
     */
    #[DataProvider('writeProvider')]
    public function testWriteRunsAndAnnounces(string $shape, string $method, bool $announces): void
    {
        $class = 'FakeVendor\HelloWorld\Resource\Page\Mx\\' . $shape;
        $class::$ran = 0;
        $injector = new Injector(
            new FakeEtagPoolModule(ModuleFactory::getInstance('FakeVendor\HelloWorld')),
            __DIR__ . '/tmp',
        );
        $resource = $injector->getInstance(ResourceInterface::class);
        $uri = 'page://self/mx/' . $shape . '?id=1';
        $resource->get($uri);                                        // warm the cache
        $ro = $resource->{strtolower(substr($method, 2))}($uri);     // then write
        assert($ro instanceof ResourceObject);

        $this->assertSame(1, $class::$ran, $shape . '::' . $method . ' was answered from the cache instead of running');
        $this->assertSame(204, $ro->code, $shape . '::' . $method . ' returned the cached representation, not its own');

        if (! $announces) {
            return;
        }

        $log = (string) json_encode($injector->getInstance(SemanticLoggerInterface::class, CacheLog::class)->flush());
        $this->assertGreaterThan(0, substr_count($log, '"command"'), $shape . '::' . $method . ' left no command scope: nothing was told that the state changed');
    }
}
