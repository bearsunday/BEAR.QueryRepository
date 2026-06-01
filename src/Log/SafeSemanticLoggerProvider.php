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
 * @implements ProviderInterface<SemanticLoggerInterface>
 */
final class SafeSemanticLoggerProvider implements ProviderInterface
{
    #[Override]
    public function get(): SemanticLoggerInterface
    {
        return new SafeSemanticLogger(new SemanticLogger());
    }
}
