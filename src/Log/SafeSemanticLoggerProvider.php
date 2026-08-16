<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log;

use Koriym\SemanticLogger\SemanticLogger;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Override;
use Ray\Di\ProviderInterface;

/**
 * Provides the shared SemanticLoggerInterface (a SafeSemanticLogger wrapping a SemanticLogger)
 *
 * Built via a provider so the public SemanticLoggerInterface binding can resolve to
 * SafeSemanticLogger without a self-referential cycle (SafeSemanticLogger consumes the
 * concrete SemanticLogger as its delegate). Bind this in Singleton scope so open() from
 * an interceptor and event() from storage share one session.
 *
 * The sink is required rather than optional: this provider is bound only by a log module,
 * and a module that turns recording on also decides where the session goes.
 *
 * @implements ProviderInterface<SemanticLoggerInterface>
 */
final class SafeSemanticLoggerProvider implements ProviderInterface
{
    public function __construct(private LogSinkInterface $sink)
    {
    }

    #[Override]
    public function get(): SemanticLoggerInterface
    {
        return new SafeSemanticLogger(new SemanticLogger(), $this->sink);
    }
}
