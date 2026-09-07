<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\Purge;
use BEAR\Resource\ResourceObject;

/** A #[Cacheable] collection whose write is a POST, the method a form submits */
#[Cacheable]
class PostWriter extends ResourceObject
{
    /** How many times the representation was generated, so a refresh is observable */
    public static int $gets = 0;

    public function onGet(string $id): static
    {
        self::$gets++;
        // The generation is in the body, so a stored entry says which run produced it
        $this->body = ['id' => $id, 'generation' => self::$gets];

        return $this;
    }

    #[Purge(uri: 'app://self/refresh-dest{?id}')]
    public function onPost(string $id): static
    {
        unset($id);

        return $this;
    }
}
