<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\DonutCache;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/** A #[DonutCache] page that adds its run count to the body only when the body has none, over a hole a test makes fail */
#[DonutCache]
class RunCountingFlakyDonut extends ResourceObject
{
    public static int $runs = 0;

    #[Embed(rel: 'flaky', src: 'app://self/flaky-hole')]
    public function onGet(): static
    {
        $this->body += ['run' => ++self::$runs];

        return $this;
    }
}
