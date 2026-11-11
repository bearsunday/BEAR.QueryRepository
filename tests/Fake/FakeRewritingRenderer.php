<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\RenderInterface;
use BEAR\Resource\ResourceObject;
use Override;

/** Leaves its mark on the resource as HalRenderer does: overwrites a header, adds one, rewrites the body */
final class FakeRewritingRenderer implements RenderInterface
{
    public const VIEW = 'rendered';
    public const HEADERS = ['Content-Type' => 'text/x-rendered', 'X-Rendered' => '1'];

    #[Override]
    public function render(ResourceObject $ro): string
    {
        $ro->headers = [...$ro->headers, ...self::HEADERS];
        $ro->body = ['rewritten' => true];
        $ro->view = self::VIEW;

        return self::VIEW;
    }
}
