<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;

use function assert;

final class DonutRepository
{
    /** @var ResourceStorageInterface */
    private $resourceStorage;

    /** @var HeaderSetter */
    private $headerSetter;

    /** @var ResourceInterface */
    private $resource;

    public function __construct(HeaderSetter $headerSetter, ResourceStorageInterface $resourceStorage, ResourceInterface $resource)
    {
        $this->resourceStorage = $resourceStorage;
        $this->headerSetter = $headerSetter;
        $this->resource = $resource;
    }

    public function refreshDonut(ResourceObject $ro): ?ResourceObject
    {
        $donut = $this->resourceStorage->getDonut($ro->uri);
        if (! $donut instanceof ResourceDonut) {
            return null;
        }

        $donut->refresh($this->resource, $ro);
        ($this->headerSetter)($ro, $donut->ttl, null);
        $ro->headers['ETag'] .= 'r'; // mark refreshed by resource static
        $this->saveView($ro, $donut->ttl);

        return $ro;
    }

    public function createDonut(ResourceObject $ro, ?int $ttl): ResourceObject
    {
        $renderer = new DonutRenderer();
        $etags = new Etags();
        $donut = ResourceDonut::create($ro, $renderer, $etags, $ttl);
        $this->resourceStorage->saveDonut($ro->uri, $donut);

        $donut->render($ro, $renderer);
        $etags->setSurrogateKey($ro);
        ($this->headerSetter)($ro, 0, null);
        $this->saveView($ro, $ttl);

        return $ro;
    }

    private function saveView(ResourceObject $ro, ?int $ttl): bool
    {
        assert(isset($ro->headers['ETag']));
        $this->resourceStorage->updateEtag($ro->uri, $ro->headers['ETag'], $ttl);

        return $this->resourceStorage->saveDonutView($ro, $ttl);
    }
}
