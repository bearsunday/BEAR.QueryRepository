<?php

declare(strict_types=1);

use BEAR\QueryRepository\ModuleFactory;
use BEAR\RepositoryModule\Annotation\ResourceObjectPool;
use BEAR\Resource\ResourceInterface;
use BEAR\Sunday\Extension\Transfer\HttpCacheInterface;
use BEAR\Sunday\Extension\Transfer\TransferInterface;
use BEAR\Sunday\Provide\Transfer\HttpResponderModule;
use Ray\Di\AbstractModule;
use Ray\Di\Injector;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

/**
 * What an application's bootstrap does with a request, as the router of `php -S`
 *
 * The pool is on disk in QR_NOT_MODIFIED_DIR, since each request is a new process state.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$dir = (string) getenv('QR_NOT_MODIFIED_DIR');
$module = ModuleFactory::getInstance('FakeVendor\HelloWorld');
$module->override(new class ($dir) extends AbstractModule {
    public function __construct(private readonly string $dir)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->install(new HttpResponderModule());
        $this->bind(AdapterInterface::class)->annotatedWith(ResourceObjectPool::class)->toInstance(new FilesystemAdapter('', 0, $this->dir . '/pool'));
    }
});
$injector = new Injector($module, $dir);

$httpCache = $injector->getInstance(HttpCacheInterface::class);
if ($httpCache->isNotModified($_SERVER)) {
    $httpCache->transfer();

    return;
}

$ro = $injector->getInstance(ResourceInterface::class)->get('app://self/cacheable-with-shared-lifetime');
$injector->getInstance(TransferInterface::class)($ro, $_SERVER);
