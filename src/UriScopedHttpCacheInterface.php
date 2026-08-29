<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractUri;

/**
 * The conditional-request answer, scoped to the resource that was asked for
 *
 * `HttpCacheInterface::isNotModified()` receives the request environment and nothing else, so it
 * can only ask whether the offered validator is alive somewhere: a client or intermediary that
 * returns a validator it obtained for another URI is answered 304 about content this server never
 * sent it. Routing is what supplies the missing half, and routing is cheap - the cost a 304 avoids
 * is running the resource, not matching a path.
 */
interface UriScopedHttpCacheInterface
{
    /** @param array{HTTP_IF_NONE_MATCH?: string} $server */
    public function isNotModifiedFor(AbstractUri $uri, array $server): bool;
}
