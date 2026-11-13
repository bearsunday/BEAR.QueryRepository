<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\DonutCache;
use BEAR\Resource\Annotation\Embed;

/** RunCountingFlakyDonut over a hole a test makes fail to construct */
#[DonutCache]
class RunCountingUnbuildableDonut extends RunCountingFlakyDonut
{
    #[Embed(rel: 'flaky', src: 'app://self/unbuildable-hole')]
    public function onGet(): static
    {
        $this->body += ['run' => ++self::$runs];

        return $this;
    }
}
