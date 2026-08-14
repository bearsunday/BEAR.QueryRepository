<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\RenderInterface;
use BEAR\Resource\ResourceObject;
use Override;

final class FakeThrowingRenderer implements RenderInterface
{
    #[Override]
    public function render(ResourceObject $ro): string
    {
        throw new FakeTemplateNotFound('template not found: user.html.twig');
    }
}
