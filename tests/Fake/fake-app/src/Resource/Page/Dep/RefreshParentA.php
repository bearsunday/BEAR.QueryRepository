<?php

namespace FakeVendor\HelloWorld\Resource\Page\Dep;

use BEAR\RepositoryModule\Annotation\Refresh;
use BEAR\Resource\ResourceObject;

class RefreshParentA extends ResourceObject
{
    #[Refresh(uri: 'page://self/dep/parent-a')]
    public function onPut()
    {
        return $this;
    }
}
