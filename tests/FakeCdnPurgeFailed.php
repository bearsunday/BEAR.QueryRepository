<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use RuntimeException;

/** A CDN purge failure: named so the class recorded in cache_error is not the same generic throwable a dead pool raises */
final class FakeCdnPurgeFailed extends RuntimeException
{
}
