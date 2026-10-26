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
        // Keyed by placeholder, not appended: a second MISS of the same page in one
        // long-running container (Swoole/RoadRunner) re-renders the same hole, and the
        // fresh view must replace the stale one instead of piling up behind it (strtr()
        // below only ever sees the latest entry for a given placeholder).
        $this->views[ResourceDonut::placeholder($uri)] = $view;
    }

    #[Override]
    public function render(string $template): string
    {
        return strtr($template, $this->views);
    }
}
