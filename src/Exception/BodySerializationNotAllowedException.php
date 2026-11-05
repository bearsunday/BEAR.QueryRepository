<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Exception;

/** Not a CacheStoreException, which CacheInterceptor degrades to a warning */
final class BodySerializationNotAllowedException extends RuntimeException
{
}
