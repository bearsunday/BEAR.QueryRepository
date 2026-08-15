<?php

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Refresh;
use BEAR\Resource\Code;
use BEAR\Resource\ResourceObject;

/**
 * Fails with the exact code the interceptor treats as the first error, so the
 * #[Refresh] must be skipped (the third command producer's 4xx branch).
 */
class RefreshError extends ResourceObject
{
    #[Refresh(uri: 'app://self/refresh-dest{?id}')]
    public function onPut(mixed $id)
    {
        unset($id);
        $this->code = Code::BAD_REQUEST;

        return $this;
    }
}
