<?php

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\DonutCache;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/**
 * A #[DonutCache] page that reads its embedded child's value in onGet() AND renders the
 * same child as a hole - the two access paths that used to run the child's onGet() twice
 * (issue: double execution / MISS-HIT inconsistency). `read` is the body-read value, `{{
 * counter }}` in the template is the hole; both must come from the one execution.
 *
 * `$body`'s default `label` key is a plain value already sitting in the body before
 * `#[Embed]` adds `counter` to it - the shape EmbedOnceInterceptor::swap() must leave
 * alone (only an `AbstractRequest`/`Request` entry is a candidate to wrap).
 */
#[DonutCache]
class CounterDonut extends ResourceObject
{
    /** @var array<string, mixed>|null */
    public $body = ['label' => 'counter-donut'];

    #[Embed(rel: 'counter', src: 'app://self/counter')]
    public function onGet(): static
    {
        $this->body['read'] = $this->body['counter']['call'];

        return $this;
    }
}
