<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\ResourceStorageUnserializeException;
use BEAR\RepositoryModule\Annotation\EtagPool;
use Error;
use Psr\Cache\CacheItemPoolInterface;
use Ray\Di\Di\Inject;
use Ray\Di\Exception\Unbound;
use Ray\Di\InjectorInterface;
use Ray\PsrCacheModule\Annotation\Shared;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;

use function assert;

trait ResourceStorageCacheableTrait
{
    /**
     * @var ?InjectorInterface
     * @psalm-suppress PropertyNotSetInConstructor
     */
    protected $injector;

    /** @Inject */
    #[Inject]
    final public function setInjector(InjectorInterface $injector): void
    {
        $this->injector = $injector;
    }

    /** @return array{logger: RepositoryLoggerInterface, purger: PurgerInterface, uriTag: UriTagInterface, saver: ResourceStorageSaver, knownTagTtl: float, injector: InjectorInterface} */
    final public function __serialize(): array
    {
        assert($this->injector instanceof InjectorInterface);

        return [
            'logger' => $this->logger,
            'purger' => $this->purger,
            'uriTag' => $this->uriTag,
            'saver' => $this->saver,
            'knownTagTtl' => $this->knownTagTtl,
            'injector' => $this->injector,
        ];
    }

    /** @param array{logger: RepositoryLoggerInterface, purger: PurgerInterface, uriTag: UriTagInterface, saver: ResourceStorageSaver, knownTagTtl: float, injector: InjectorInterface} $data */
    final public function __unserialize(array $data): void
    {
        try {
            $this->unserialize($data);
            // @codeCoverageIgnoreStart
        } catch (Error $e) {
            throw new ResourceStorageUnserializeException($e->getMessage());
            // @codeCoverageIgnoreEnd
        }
    }

    /** @param array{logger: RepositoryLoggerInterface, purger: PurgerInterface, uriTag: UriTagInterface, saver: ResourceStorageSaver, knownTagTtl: float, injector: InjectorInterface} $data */
    private function unserialize(array $data): void
    {
        $this->logger = $data['logger'];
        $this->purger = $data['purger'];
        $this->uriTag = $data['uriTag'];
        $this->saver = $data['saver'];
        $pool = $data['injector']->getInstance(CacheItemPoolInterface::class, Shared::class);
        try {
            $maybeEtagPool = $data['injector']->getInstance(CacheItemPoolInterface::class, EtagPool::class);
            // @codeCoverageIgnoreStart
        } catch (Unbound) {
            $maybeEtagPool = null;
            // @codeCoverageIgnoreEnd
        }

        assert($pool instanceof AdapterInterface);
        /** @psalm-suppress all */
        $etagPool = $maybeEtagPool instanceof AdapterInterface ? $maybeEtagPool : $pool;
        $this->roPool = new TagAwareAdapter($pool, $etagPool, $data['knownTagTtl']);
        $this->etagPool = new TagAwareAdapter($etagPool, $etagPool, $data['knownTagTtl']);
        $this->knownTagTtl = $data['knownTagTtl'];
        $this->injector = $data['injector'];
    }
}
