<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Serializable;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\TagAwareAdapter as OriginAdapter;

use function call_user_func_array;
use function func_get_args;
use function serialize;
use function unserialize;

/**
 * Serializable TagAwareAdapter
 */
final class SerializableTagAwareAdapter extends OriginAdapter implements Serializable
{
    /** @var array<int, mixed> */
    private $args;

    public function __construct(AdapterInterface $itemsPool, ?AdapterInterface $tagsPool = null, float $knownTagVersionsTtl = 0.15)
    {
        $this->args = func_get_args();
        parent::__construct($itemsPool, $tagsPool, $knownTagVersionsTtl);
    }

    /**
     * @inheritDoc
     */
    public function serialize()
    {
        return serialize($this->args);
    }

    /**
     * @param string $data
     *
     * @inheritDoc
     */
    public function unserialize($data)
    {
        call_user_func_array([$this, '__construct'], unserialize($data)); // @phpstan-ignore-line
    }
}
