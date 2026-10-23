<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Override;

use function str_replace;

final class DonutRenderer implements DonutRendererInterface
{
    /** @var list<string> */
    private array $searches = [];

    /** @var list<string> */
    private array $views = [];

    #[Override]
    public function setView(string $uri, string $view): void
    {
        $this->searches[] = ResourceDonut::placeholder($uri);
        $this->views[] = $view;
    }

    #[Override]
    public function render(string $template): string
    {
        return str_replace($this->searches, $this->views, $template);
    }
}
