<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Log\Context\CommandContext;
use BEAR\RepositoryModule\Annotation\AbstractCommand;
use Ray\Aop\MethodInvocation;

/**
 * Builds the CommandContext that opens a write/command logging scope
 *
 * Captures the command method (e.g. onPut, onDelete) and its #[Refresh] /
 * #[Purge] annotations, so the cause of the invalidations that follow is
 * recorded as the open node, with the purges nested beneath it.
 *
 * Shared by CommandInterceptor, DonutCommandInterceptor and RefreshInterceptor
 * to avoid duplication.
 */
final class CommandContextFactory
{
    /** @param MethodInvocation<object> $invocation */
    public function __invoke(MethodInvocation $invocation): CommandContext
    {
        $method = $invocation->getMethod();
        $annotations = [];
        foreach ($method->getAnnotations() as $annotation) {
            if (! $annotation instanceof AbstractCommand) {
                continue;
            }

            // AbstractCommand::$uri is a declared string; no cast needed.
            $annotations[] = ['class' => $annotation::class, 'uri' => $annotation->uri];
        }

        return new CommandContext($method->getName(), $annotations);
    }
}
