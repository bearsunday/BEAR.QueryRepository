<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log;

use Koriym\SemanticLogger\SemanticLoggerInterface;
use Override;
use Ray\Di\ProviderInterface;

/**
 * Provides the cache log: a SafeSemanticLogger over a swappable session store, bound with #[CacheLog]
 *
 * Built via a provider so the #[CacheLog] binding can resolve to
 * SafeSemanticLogger without a self-referential cycle. Bind this in Singleton scope so open()
 * from an interceptor and event() from storage reach one facade; the store decides whether
 * they share one session.
 *
 * The sink is required rather than optional: this provider is bound only by a log module,
 * and a module that turns recording on also decides where the session goes.
 *
 * @implements ProviderInterface<SemanticLoggerInterface>
 */
final class SafeSemanticLoggerProvider implements ProviderInterface
{
    public function __construct(
        private LogSinkInterface $sink,
        private SessionStoreInterface $store,
    ) {
    }

    #[Override]
    public function get(): SemanticLoggerInterface
    {
        return new SafeSemanticLogger($this->sink, $this->store);
    }
}
