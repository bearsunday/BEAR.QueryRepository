<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Override;

use function strtr;

/** @internal */
final class DonutRenderer implements DonutRendererInterface
{
    /** @var array<string, string> */
    private array $views = [];

    #[Override]
    public function setView(string $uri, string $view): void
    {
        // One view per placeholder: in a long-running container the latest render of a hole must win.
        $this->views[ResourceDonut::placeholder($uri)] = $view;
    }

    #[Override]
    public function render(string $template): string
    {
        return strtr($template, $this->views);
    }
}
