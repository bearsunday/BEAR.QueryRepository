<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractRequest;
use BEAR\Resource\InvokerInterface;
use BEAR\Resource\ResourceObject;
use Override;

/**
 * Answers a request with the result an entry stored for it
 *
 * @internal
 */
final class StoredResult implements InvokerInterface
{
    #[Override]
    public function invoke(AbstractRequest $request): ResourceObject
    {
        return $request->resourceObject;
    }
}
