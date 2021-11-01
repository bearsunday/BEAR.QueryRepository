<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use function sprintf;
use function str_replace;

class DonutRenderer
{
    /** @var list<string> */
    private $searches = [];

    /** @var list<string> */
    private $views = [];

    public function setView(string $uri, string $view): void
    {
        $this->searches[] = sprintf(ResourceDonut::FOMRAT, $uri);
        $this->views[] = $view;
    }

    public function render(string $template): string
    {
        return str_replace($this->searches, $this->views, $template);
    }
}
