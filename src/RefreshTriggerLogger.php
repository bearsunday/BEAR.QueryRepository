<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\AbstractCommand;
use BEAR\Resource\ResourceObject;
use Ray\Aop\MethodInvocation;

/**
 * Emits the `refresh-trigger` causality event
 *
 * Records which command method (e.g. onPut, onDelete) and which #[Refresh] /
 * #[Purge] annotations triggered a cache invalidation, so the cause of a later
 * `invalidate-etag` / `cache-miss` can be reconstructed from the log alone.
 *
 * Shared by CommandInterceptor and RefreshInterceptor to avoid duplication.
 */
final readonly class RefreshTriggerLogger
{
    public function __construct(
        private RepositoryLoggerInterface $logger,
    ) {
    }

    /** @param MethodInvocation<object> $invocation */
    public function __invoke(MethodInvocation $invocation, ResourceObject $ro): void
    {
        $method = $invocation->getMethod();
        $triggers = [];
        foreach ($method->getAnnotations() as $annotation) {
            if (! $annotation instanceof AbstractCommand) {
                continue;
            }

            $triggers[] = ['class' => $annotation::class, 'uri' => $annotation->uri];
        }

        if ($triggers === []) {
            return;
        }

        $this->logger->log('refresh-trigger', [
            'uri' => (string) $ro->uri,
            'method' => $method->getName(),
            'annotations' => $triggers,
        ]);
    }
}
