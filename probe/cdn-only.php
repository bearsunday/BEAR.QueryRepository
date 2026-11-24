<?php

declare(strict_types=1);

use BEAR\QueryRepository\ModuleFactory;
use BEAR\QueryRepository\PurgerInterface;
use BEAR\QueryRepository\QueryRepositoryInterface;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\Uri;
use Madapaja\TwigModule\TwigModule;
use Ray\Di\AbstractModule;
use Ray\Di\Injector;

require dirname(__DIR__) . '/vendor/autoload.php';

final class RecordingPurger implements PurgerInterface
{
    /** @var list<string> */
    public static array $purged = [];

    public function __invoke(string $tag): void
    {
        self::$purged[] = $tag;
    }
}

$module = ModuleFactory::getInstance('FakeVendor\HelloWorld');
$module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));
$module->override(new class extends AbstractModule {
    protected function configure(): void
    {
        $this->bind(PurgerInterface::class)->to(RecordingPurger::class);
    }
});
$injector = new Injector($module, dirname(__DIR__) . '/tests/tmp');
$resource = $injector->getInstance(ResourceInterface::class);
$repository = $injector->getInstance(QueryRepositoryInterface::class);

$ro = $resource->get('page://self/html/probe-cdn-only', ['id' => 7]);
echo 'page ', $ro->code, "\n";
echo '  view: ', (string) $ro, "\n";
foreach ($ro->headers as $name => $value) {
    echo "  $name: $value\n";
}
$pageKeys = explode(' ', $ro->headers['Surrogate-Key'] ?? '');

foreach (['page://self/html/mutable-comment', 'page://self/html/probe-plain-child', 'app://self/taxonomy?id=7', 'page://self/html/probe-cdn-only', 'page://self/html/probe-cdn-only?id=7', 'app://self/taxonomy?id=8'] as $uri) {
    RecordingPurger::$purged = [];
    $repository->purge(new Uri($uri));
    $sent = explode(' ', implode(' ', RecordingPurger::$purged));
    $hit = array_values(array_intersect($sent, $pageKeys));
    printf("purge %-40s cdn<- %-50s reaches page: %s\n", $uri, implode(' ', $sent), $hit ? 'yes (' . implode(' ', $hit) . ')' : 'NO');
}

RecordingPurger::$purged = [];
$w = $resource->patch('app://self/user', ['id' => 1, 'name' => 'x']);
echo 'write PATCH app://self/user?id=1 code=', $w->code, ' cdn<- ', implode(' | ', RecordingPurger::$purged), "\n";
