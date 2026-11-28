<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\HttpCache;

#[HttpCache(maxAge: 60)]
class InheritedHttpCacheChild extends InheritedHttpCacheBase
{
}
