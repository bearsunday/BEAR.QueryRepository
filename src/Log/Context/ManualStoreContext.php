<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log\Context;

use Koriym\SemanticLogger\AbstractContext;

/**
 * Open: an application-initiated (manual) store of a resource.
 *
 * Emitted only when put() is top-level (not nested inside a request GET or a
 * write command). A direct put has no enclosing scope, so its save events would
 * be dropped at flush; wrapping it here keeps the write visible in the log.
 */
final class ManualStoreContext extends AbstractContext
{
    public const TYPE = 'manual_store';
    public const SCHEMA_URL = 'https://bearsunday.github.io/BEAR.QueryRepository/schemas/context/manual_store.json';

    public function __construct(
        public readonly string $uri,
    ) {
    }
}
