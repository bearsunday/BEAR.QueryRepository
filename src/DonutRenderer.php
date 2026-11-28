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
        // Indexed by placeholder so rendering the same hole twice replaces the earlier view
        $this->views[ResourceDonut::placeholder($uri)] = $view;
    }

    #[Override]
    public function render(string $template): string
    {
        return strtr($template, $this->views);
    }
}
