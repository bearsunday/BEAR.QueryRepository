<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\QueryRepository\UncacheableMarkInterface;
use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/**
 * The #234 "called-after-mark" scenario: marks itself, then calls a DIFFERENT `#[Cacheable]`
 * resource (InnocentCacheableSibling) synchronously - still inside this resource's own
 * execution, after mark() - rather than leaving the `#[Embed]` lazy for a template to resolve
 * later (this resource is type='value', there is no template). The descendant's own write must
 * also be refused: it shares this still-open resource's own (TOP_LEVEL) scope, which only
 * isMarkedForCurrentScope() sees - wasMarkedSinceCurrentWrite() ALONE misses it, because the
 * descendant's own write window opens AFTER the mark already fired.
 */
#[Cacheable]
class MarksThenCallsCacheableSibling extends ResourceObject
{
    public function __construct(
        private readonly UncacheableMarkInterface $mark,
    ) {
    }

    #[Embed(rel: 'inner', src: 'app://self/innocent-cacheable-sibling')]
    public function onGet(): static
    {
        $this->mark->mark('csrf-token-issued');
        // Stringifying now forces the embedded request's full get/put cycle to run HERE,
        // synchronously, while this resource's own scope is still open - not left as a lazy
        // Request for a template to resolve later.
        $this->body['inner'] = (string) $this->body['inner'];

        return $this;
    }
}
