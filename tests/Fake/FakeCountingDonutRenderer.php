<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Override;

final class FakeCountingDonutRenderer implements DonutRendererInterface
{
    /** @var list<self> */
    private static array $instances = [];

    private DonutRenderer $inner;

    public function __construct()
    {
        $this->inner = new DonutRenderer();
        self::$instances[] = $this;
    }

    public static function reset(): void
    {
        self::$instances = [];
    }

    /** @return list<self> */
    public static function instances(): array
    {
        return self::$instances;
    }

    #[Override]
    public function setView(string $uri, string $view): void
    {
        $this->inner->setView($uri, $view);
    }

    #[Override]
    public function render(string $template): string
    {
        return $this->inner->render($template);
    }
}
