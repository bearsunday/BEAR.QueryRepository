<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Refresh;
use BEAR\Resource\ResourceObject;

/**
 * A command method that forgets to return its resource
 *
 * The interceptor has to refresh something, and what it was handed is not a resource. Saying so by
 * class name is the only useful answer: continuing would refresh whatever the previous request
 * left in scope.
 */
class RefreshNotResourceObject extends ResourceObject
{
    /** @return string the mistake this fixture exists to make */
    #[Refresh(uri: "app://self/refresh-dest{?id}")]
    public function onPut(): string
    {
        return 'not a resource object';
    }
}
