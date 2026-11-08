<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;
use BEAR\Resource\Uri;
use Closure;
use FakeVendor\HelloWorld\Resource\App\DeletableWriter;
use FakeVendor\HelloWorld\Resource\App\StatusWriter;
use FakeVendor\HelloWorld\Resource\Page\Html\CreatedDonutWriter;
use Madapaja\TwigModule\TwigModule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

use function array_diff_key;
use function array_flip;
use function dirname;

/** A write refreshes its entry with a GET on a new instance and keeps its own response */
class RefreshOnWriteTest extends TestCase
{
    private ResourceInterface $resource;
    private QueryRepositoryInterface $repository;

    protected function setUp(): void
    {
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance('FakeVendor\HelloWorld'));
        $module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));
        $injector = new Injector($module, __DIR__ . '/tmp');
        $this->resource = $injector->getInstance(ResourceInterface::class);
        $this->repository = $injector->getInstance(QueryRepositoryInterface::class);
        StatusWriter::$gets = 0;
        CreatedDonutWriter::$gets = 0;
        DeletableWriter::$data = DeletableWriter::DATA;

        parent::setUp();
    }

    /** @return array<string, array{Closure(ResourceInterface): ResourceObject, int}> */
    public static function nonOkWriteProvider(): array
    {
        return [
            '201 PUT' => [static fn (ResourceInterface $resource): ResourceObject => $resource->put('app://self/status-writer', ['id' => '1', 'title' => 'new']), 201],
            '204 DELETE' => [static fn (ResourceInterface $resource): ResourceObject => $resource->delete('app://self/status-writer', ['id' => '1']), 204],
        ];
    }

    /** @param Closure(ResourceInterface): ResourceObject $write */
    #[DataProvider('nonOkWriteProvider')]
    public function testANon200WriteStillRefreshesItsEntry(Closure $write, int $code): void
    {
        $this->resource->get('app://self/status-writer', ['id' => '1']);

        $this->assertSame($code, $write($this->resource)->code);
        $state = $this->repository->get(new Uri('app://self/status-writer?id=1'));
        $this->assertInstanceOf(ResourceState::class, $state, 'the write left its entry empty');
        $this->assertSame(200, $state->code);
        $this->assertSame(['id' => '1', 'generation' => 2], $state->body, 'the write body leaked into the GET representation');
        $this->assertArrayNotHasKey('Location', $state->headers);
        $this->resource->get('app://self/status-writer', ['id' => '1']);
        $this->assertSame(2, StatusWriter::$gets, 'the GET after the write was not served from the refreshed entry');
    }

    public function testTheWriterGetsItsOwnResponse(): void
    {
        $this->resource->get('app://self/status-writer', ['id' => '1']);

        $put = $this->resource->put('app://self/status-writer', ['id' => '1', 'title' => 'new']);

        $this->assertSame(201, $put->code);
        $this->assertSame(['id' => '1', 'title' => 'new'], $put->body);
        $this->assertSame(['Location' => '/status-writer?id=1'], $put->headers, 'GET validators reached the write response');
        $this->assertSame(['id' => '1', 'title' => 'new'], $put->uri->query);
    }

    public function testADonutWriteRefreshesItsEntryAndKeepsItsOwnResponse(): void
    {
        $this->resource->get('page://self/html/created-donut-writer', ['id' => 1]);

        $put = $this->resource->put('page://self/html/created-donut-writer', ['id' => 1, 'title' => 'new']);
        $get = $this->resource->get('page://self/html/created-donut-writer', ['id' => 1]);

        $this->assertSame(201, $put->code);
        $this->assertSame(['id' => 1, 'title' => 'new'], $put->body);
        $this->assertSame(200, $get->code, 'the write code was stored as the GET representation');
        $this->assertSame(2, CreatedDonutWriter::$gets, 'the GET after the write was not served from the refreshed entry');
        $this->assertSame('created:1:2', (string) $get, 'the write body leaked into the GET representation');
        $this->assertArrayNotHasKey('Location', $get->headers, 'the write header was stored as the GET representation');
    }

    public function testTheRefreshedEntryMatchesWhatAPlainGetStores(): void
    {
        $uri = new Uri('app://self/declared-defaults-writer?id=1');
        $this->resource->get('app://self/declared-defaults-writer', ['id' => '1']);
        $plain = $this->repository->get($uri);
        $this->assertInstanceOf(ResourceState::class, $plain);

        $this->resource->put('app://self/declared-defaults-writer', ['id' => '1']);

        $refreshed = $this->repository->get($uri);
        $this->assertInstanceOf(ResourceState::class, $refreshed);
        $validators = array_flip([Header::ETAG, Header::LAST_MODIFIED, Header::AGE]);
        $this->assertSame($plain->code, $refreshed->code);
        $this->assertSame($plain->body, $refreshed->body, 'the refresh lost the declared or constructor-set body');
        $this->assertSame(array_diff_key($plain->headers, $validators), array_diff_key($refreshed->headers, $validators), 'the refresh lost a declared or constructor-set header');
        $this->assertSame('get', $refreshed->uri->method, 'the entry keeps the write method on its URI');
    }

    public function testA200WriteGetsNoValidatorsOfTheRefreshedGet(): void
    {
        $put = $this->resource->put('app://self/declared-defaults-writer', ['id' => '1']);

        $this->assertSame(200, $put->code);
        $this->assertSame(['written' => '1'], $put->body);
        $this->assertSame(['Cache-Control' => 'public', 'Vary' => 'Accept'], $put->headers, 'the refreshed GET headers reached the write response');
    }

    public function testAPurgeTemplateExpandsFromTheWritesOwnQuery(): void
    {
        $dest = new Uri('app://self/refresh-dest?id=2');
        $this->repository->put($this->resource->get('app://self/refresh-dest', ['id' => '2']));
        $this->assertInstanceOf(ResourceState::class, $this->repository->get($dest));

        $this->resource->put('app://self/template-writer', ['id' => '1', 'category' => '2']);

        $this->assertNull($this->repository->get($dest), 'the #[Purge] lost the write-only parameter it names');
    }

    public function testADeleteWhoseRefreshGetIs404StillAnswersItsOwnCode(): void
    {
        $this->resource->get('app://self/deletable-writer', ['id' => '1']);

        $delete = $this->resource->delete('app://self/deletable-writer', ['id' => '1']);

        $this->assertSame(204, $delete->code, 'the refresh GET 404 became the DELETE response');
        $this->assertNull($this->repository->get(new Uri('app://self/deletable-writer?id=1')));
        $this->assertSame(404, $this->resource->get('app://self/deletable-writer', ['id' => '1'])->code);
    }

    public function testHrefAfterAWriteFollowsTheWritesOwnLink(): void
    {
        $this->resource->put('app://self/status-writer', ['id' => '1', 'title' => 'new']);

        $this->assertSame('app://self/refresh-dest?id=1', (string) $this->resource->href('done')->uri, 'href() anchored on the refresh GET instead of the write');
    }
}
