<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceInterface;
use Madapaja\TwigModule\TwigModule;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

use function assert;
use function dirname;

/**
 * A #[DonutCache] page whose #[Embed] hole URI template carries two query parameters,
 * rendered through the project's standard `{{ hole }}` (no `|raw`), reproduces #232:
 *
 * Twig's default autoescape turns the placeholder's `&` into `&amp;`. First request: the
 * `str_replace` in `DonutRenderer::render()` searches for the raw (un-escaped) placeholder
 * and never matches the escaped one actually embedded in the rendered view, so the raw
 * `[le:...]` text leaks to the client instead of the hole's content. Second request: the
 * cached template (captured post-escape) is fed through `ResourceDonut::URI_REGEX` and
 * `$resource->get($uri)` in `refresh()`; the stray `amp;` corrupts the second query
 * parameter, which throws `BEAR\Resource\Exception\ParameterException` for a required
 * param (quieter but equally wrong for an optional one).
 */
class DonutHolePlaceholderEscapingTest extends TestCase
{
    private ResourceInterface $resource;

    protected function setUp(): void
    {
        static $injector;

        $namespace = 'FakeVendor\HelloWorld';
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance($namespace));
        $module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));
        if (! $injector) {
            $injector = new Injector($module, __DIR__ . '/tmp');
        }

        assert($injector instanceof Injector);
        $this->resource = $injector->getInstance(ResourceInterface::class);

        parent::setUp();
    }

    public function testFirstRequestSubstitutesTheHoleInsteadOfLeakingThePlaceholder(): void
    {
        $ro = $this->resource->get('page://self/html/donut-cache-with-multi-param-query-hole');
        $view = (string) $ro;

        $this->assertStringNotContainsString('[le:', $view, 'the escaped placeholder must still be substituted, not served raw');
        $this->assertStringContainsString('<hole>1-2</hole>', $view, 'the hole renders with both query params, not a mangled one');
    }

    /** @depends testFirstRequestSubstitutesTheHoleInsteadOfLeakingThePlaceholder */
    public function testSecondRequestRefreshesWithTheSameQueryInsteadOfThrowing(): void
    {
        $ro = $this->resource->get('page://self/html/donut-cache-with-multi-param-query-hole');
        $view = (string) $ro;

        $this->assertStringNotContainsString('[le:', $view);
        $this->assertStringContainsString('<hole>1-2</hole>', $view, 'refresh() must re-fetch the hole with BOTH original query params');
    }
}
