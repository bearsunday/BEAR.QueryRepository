<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\ResourceObjectPool;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;
use BEAR\Resource\Uri;
use BEAR\Sunday\Extension\Transfer\HttpCacheInterface;
use Override;
use PHPUnit\Framework\TestCase;
use Ray\Di\AbstractModule;
use Ray\Di\Injector;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;

/** Requests that differ in what X_VARY names share one pool: each has its own entry under the same URI */
class VaryEntryTest extends TestCase
{
    private const USER = 'app://self/user?id=1';
    private const JA = ['X_VARY' => 'lang', 'X_LANG' => 'ja'];
    private const EN = ['X_VARY' => 'lang', 'X_LANG' => 'en'];

    private TagAwareAdapterInterface $pool;

    protected function setUp(): void
    {
        $this->pool = new TagAwareAdapter(new ArrayAdapter());
    }

    /** @param array<string, string> $server what the request's ServerContextInterface reads */
    private function request(array $server): Injector
    {
        $module = ModuleFactory::getInstance('FakeVendor\HelloWorld');
        $module->override(new class ($this->pool, $server) extends AbstractModule {
            /** @param array<string, string> $server */
            public function __construct(
                private readonly TagAwareAdapterInterface $pool,
                private readonly array $server,
            ) {
                parent::__construct();
            }

            #[Override]
            protected function configure(): void
            {
                $this->bind(TagAwareAdapterInterface::class)->annotatedWith(ResourceObjectPool::class)->toInstance($this->pool);
                $this->bind(ServerContextInterface::class)->toInstance(new FakeServerContext($this->server));
            }
        });

        return new Injector($module, __DIR__ . '/tmp');
    }

    private function answers304(Injector $request, ResourceObject $ro): bool
    {
        $httpCache = $request->getInstance(HttpCacheInterface::class);
        $this->assertInstanceOf(UriScopedHttpCacheInterface::class, $httpCache);
        $server = [Header::HTTP_IF_NONE_MATCH => (string) $ro->headers[Header::ETAG]];
        $answer = $httpCache->isNotModifiedFor($ro->uri, $server);
        $this->assertSame($answer, $httpCache->isNotModified($server), 'the answer before routing is the answer after it');

        return $answer;
    }

    public function testTheNameOfAVaryingValueTellsVariantsApart(): void
    {
        $lang = $this->request(['X_VARY' => 'lang,device', 'X_LANG' => 'x']);
        $device = $this->request(['X_VARY' => 'lang,device', 'X_DEVICE' => 'x']);

        $ofLang = $lang->getInstance(ResourceInterface::class)->get(self::USER);
        $ofDevice = $device->getInstance(ResourceInterface::class)->get(self::USER);

        $this->assertNotSame($ofLang->body, $ofDevice->body, 'the device variant is not answered from the lang variant');
        $this->assertNotSame($ofLang->headers[Header::ETAG], $ofDevice->headers[Header::ETAG]);
        $this->assertSame($ofLang->body, $lang->getInstance(ResourceInterface::class)->get(self::USER)->body);
        $this->assertSame($ofDevice->body, $device->getInstance(ResourceInterface::class)->get(self::USER)->body);
        $this->assertTrue($this->answers304($lang, $ofLang));
        $this->assertTrue($this->answers304($device, $ofDevice));
        $this->assertFalse($this->answers304($lang, $ofDevice), 'the lang variant does not validate what the device variant was sent');
        $this->assertFalse($this->answers304($device, $ofLang), 'the device variant does not validate what the lang variant was sent');
    }

    public function testVariantsStayStoredSideBySide(): void
    {
        $ja = $this->request(self::JA)->getInstance(ResourceInterface::class);
        $en = $this->request(self::EN)->getInstance(ResourceInterface::class);

        $jaFirst = $ja->get(self::USER)->body;
        $enFirst = $en->get(self::USER)->body;
        $this->assertNotSame($jaFirst, $enFirst, 'precondition: each variant ran the resource');

        foreach ([2, 3] as $round) {
            $this->assertSame($jaFirst, $ja->get(self::USER)->body, 'ja, round ' . $round);
            $this->assertSame($enFirst, $en->get(self::USER)->body, 'en, round ' . $round);
        }
    }

    public function testAPurgeDropsEveryVariant(): void
    {
        $ja = $this->request(self::JA);
        $en = $this->request(self::EN);
        $uri = new Uri(self::USER);
        $ja->getInstance(ResourceInterface::class)->get(self::USER);
        $en->getInstance(ResourceInterface::class)->get(self::USER);
        $this->assertInstanceOf(ResourceState::class, $ja->getInstance(QueryRepositoryInterface::class)->get($uri));
        $this->assertInstanceOf(ResourceState::class, $en->getInstance(QueryRepositoryInterface::class)->get($uri));

        $this->assertTrue($ja->getInstance(QueryRepositoryInterface::class)->purge($uri));

        $this->assertNull($ja->getInstance(QueryRepositoryInterface::class)->get($uri));
        $this->assertNull($en->getInstance(QueryRepositoryInterface::class)->get($uri));
    }

    /** A miss writes where this variant has no entry: it drops nothing stored for another, the parents included */
    public function testAMissInOneVariantDropsNoEntryOfAnother(): void
    {
        $ja = $this->request(self::JA);
        $en = $this->request(self::EN);
        $chain = ['page://self/dep/level-one', 'page://self/dep/level-two', 'page://self/dep/level-three'];
        $ja->getInstance(ResourceInterface::class)->get($chain[0]); // embeds level-two, which embeds level-three

        $en->getInstance(ResourceInterface::class)->get($chain[2]);

        $this->assertInstanceOf(ResourceState::class, $en->getInstance(QueryRepositoryInterface::class)->get(new Uri($chain[2])), 'en stored its own');
        foreach ($chain as $uri) {
            $this->assertInstanceOf(ResourceState::class, $ja->getInstance(QueryRepositoryInterface::class)->get(new Uri($uri)), $uri);
        }
    }
}
