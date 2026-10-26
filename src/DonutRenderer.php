<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Override;

use function strtr;

final class DonutRenderer implements DonutRendererInterface
{
    /** @var array<string, string> */
    private array $views = [];

    #[Override]
    public function setView(string $uri, string $view): void
    {
        // Keyed, not appended: a later MISS in a long-running container must replace a stale hole view.
        $this->views[ResourceDonut::placeholder($uri)] = $view;
    }

    #[Override]
    public function render(string $template): string
    {
        return strtr($template, $this->views);
    }
}
