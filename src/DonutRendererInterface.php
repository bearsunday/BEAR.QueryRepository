<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

interface DonutRendererInterface
{
    public function setView(string $uri, string $view): void;

    public function render(string $template): string;
}
