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
use function gettype;
use function implode;
use function is_array;
use function is_object;
use function json_encode;
use function property_exists;
use function strtolower;
use function substr;
use function substr_count;

/**
 * Which interceptor is woven onto which method, for every cache declaration shape
 *
 * A declaration that does not weave is silent: the resource answers correctly and the suite stays
 * green. Each shape is asserted on what the log and the response show - the write body ran, it
 * answered with its own code, no interceptor was woven twice, and the change was announced
 * exactly as many times as there are interceptors with something to announce.
 */
class WeavingMatrixTest extends TestCase
{
    /**
     * Every shape an application can write: the chain its write carries, and the scopes it opens
     *
     * The chain is the contract, order included - the defect this test exists for was a query
     * interceptor sitting first, answering the write from the store. An empty chain means the
     * declaration sits on `onGet` alone, so nothing names the write: invalidating what it changed
     * needs `#[Purge]`/`#[Refresh]` on the write itself, which no matcher can supply.
     * `CRPurge` carries two interceptors with different jobs - `RefreshInterceptor` purges the URI
     * the attribute names, `DonutCommandInterceptor` refreshes the resource - so two scopes are
     * correct there and nowhere else. `CARefresh` shows `#[RefreshCache]` adding nothing to a
     * `#[Cacheable]` class: `CommandInterceptor` already purges and regenerates, and weaving the
     * donut command interceptor beside it did the same work twice.
     *
     * @return list<array{0: string, 1: string, 2: list<string>, 3: int}>
     */
    public static function writeProvider(): array
    {
        $expected = [
            'CANone' => [[CommandInterceptor::class], 1],
            'CAPurge' => [[CommandInterceptor::class], 1],
            'CARefresh' => [[CommandInterceptor::class], 1],
            'CRNone' => [[DonutCommandInterceptor::class], 1],
            'CRPurge' => [[RefreshInterceptor::class, DonutCommandInterceptor::class], 2],
            'CRRefresh' => [[DonutCommandInterceptor::class], 1],
            'CRBoth' => [[DonutCommandInterceptor::class], 1],
            'MRNone' => [[], 0],
            'MRPurge' => [[RefreshInterceptor::class], 1],
            'MRRefresh' => [[DonutCommandInterceptor::class], 1],
            'DCNone' => [[], 0],
            'DCPurge' => [[RefreshInterceptor::class], 1],
            'DCRefresh' => [[DonutCommandInterceptor::class], 1],
            'DCMR' => [[], 0],
        ];
        $cases = [];
        foreach ($expected as $shape => [$chain, $scopes]) {
            foreach (['onPut', 'onPost', 'onDelete'] as $method) {
                $cases[] = [$shape, $method, $chain, $scopes];
            }
        }

        return $cases;
    }

    /**
     * A write runs, answers with its own code, and announces its change once per announcer
     *
     * @param list<string> $chain interceptor short class names, in the order they are woven
     */
    #[DataProvider('writeProvider')]
    public function testWriteRunsAndAnnounces(string $shape, string $method, array $chain, int $commandScopes): void
    {
        $class = 'FakeVendor\HelloWorld\Resource\Page\Mx\\' . $shape;
        $class::$ran = 0;
        $injector = new Injector(
            new FakeEtagPoolModule(ModuleFactory::getInstance('FakeVendor\HelloWorld')),
            __DIR__ . '/tmp',
        );
        $resource = $injector->getInstance(ResourceInterface::class);
        $uri = 'page://self/mx/' . $shape . '?id=1';
        $resource->get($uri);
        $ro = $resource->{strtolower(substr($method, 2))}($uri);
        assert($ro instanceof ResourceObject);

        $this->assertSame(1, $class::$ran, $shape . '::' . $method . ' was answered from the cache instead of running');
        $this->assertSame(204, $ro->code, $shape . '::' . $method . ' returned the cached representation, not its own');

        $woven = self::wovenOn($ro, $method);
        $this->assertSame($chain, $woven, $shape . '::' . $method . ' carries ' . (implode(', ', $woven) ?: 'nothing'));

        $log = (string) json_encode($injector->getInstance(SemanticLoggerInterface::class, CacheLog::class)->flush());
        $this->assertSame($commandScopes, substr_count($log, '"command"'), $shape . '::' . $method . ' opened ' . substr_count($log, '"command"') . ' command scopes, expected ' . $commandScopes);
    }

    /**
     * Interceptor class names woven onto one method, in chain order
     *
     * @return list<string>
     */
    private static function wovenOn(ResourceObject $ro, string $method): array
    {
        if (! property_exists($ro, 'bindings') || ! is_array($ro->bindings)) {
            return [];
        }

        /** @var mixed $onMethod */
        $onMethod = $ro->bindings[$method] ?? [];
        if (! is_array($onMethod)) {
            return [];
        }

        $names = [];
        /** @var mixed $interceptor */
        foreach ($onMethod as $interceptor) {
            $names[] = is_object($interceptor) ? $interceptor::class : (string) gettype($interceptor);
        }

        return $names;
    }
}
