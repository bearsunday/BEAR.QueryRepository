<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/** A page embedding an uncached list that answers 404 */
#[Cacheable]
class Page404 extends ResourceObject
{
    public static int $runs = 0;

    #[Embed(rel: 'list', src: 'app://self/rows/empty404?channelId=c1')]
    public function onGet(): static
    {
        self::$runs++;
        $this->body['page'] = true;

        return $this;
    }
}
