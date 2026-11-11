<?php

declare(strict_types=1);

use BEAR\QueryRepository\ModuleFactory;
use BEAR\RepositoryModule\Annotation\ResourceObjectPool;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;
use BEAR\Sunday\Extension\Transfer\HttpCacheInterface;
use BEAR\Sunday\Extension\Transfer\TransferInterface;
use BEAR\Sunday\Provide\Transfer\ConditionalResponseInterface;
use BEAR\Sunday\Provide\Transfer\HttpResponderModule;
use BEAR\Sunday\Provide\Transfer\Output;
use Ray\Di\AbstractModule;
use Ray\Di\Injector;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

/**
 * What an application's bootstrap does with a request, as the router of `php -S`
 *
 * The pool is on disk in QR_NOT_MODIFIED_DIR, since each request is a new process state.
 * `?repeat=content-type` is an application whose 304 repeats a Content-Type.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$dir = (string) getenv('QR_NOT_MODIFIED_DIR');
$module = ModuleFactory::getInstance('FakeVendor\HelloWorld');
$module->override(new class ($dir, ($_GET['repeat'] ?? '') === 'content-type') extends AbstractModule {
    public function __construct(private readonly string $dir, private readonly bool $repeatsContentType)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->install(new HttpResponderModule());
        $this->bind(AdapterInterface::class)->annotatedWith(ResourceObjectPool::class)->toInstance(new FilesystemAdapter('', 0, $this->dir . '/pool'));
        if (! $this->repeatsContentType) {
            return;
        }

        $this->bind(ConditionalResponseInterface::class)->toInstance(new class implements ConditionalResponseInterface {
            public function isModified(ResourceObject $ro, array $server): bool
            {
                return true;
            }

            public function getOutput(array $headers): Output
            {
                return new Output(304, ['ETag' => $headers['ETag'], 'Content-Type' => 'application/json'], '');
            }
        });
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
