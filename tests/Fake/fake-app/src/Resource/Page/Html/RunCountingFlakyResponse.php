<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/** The #[CacheableResponse] RunCountingFlakyDonut: its view is stored, so a hit that rebuilds it stores it again */
#[CacheableResponse]
class RunCountingFlakyResponse extends ResourceObject
{
    public static int $runs = 0;

    #[Embed(rel: 'flaky', src: 'app://self/flaky-hole')]
    public function onGet(): static
    {
        $this->body += ['run' => ++self::$runs];

        return $this;
    }
}
