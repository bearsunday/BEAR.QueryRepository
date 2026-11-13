<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\RenderInterface;
use BEAR\Resource\ResourceObject;

/** Prints each body value by casting it to a string, as a template does with what it is given */
final class FakeCastingRenderer implements RenderInterface
{
    public function render(ResourceObject $ro)
    {
        $view = '';
        foreach ((array) $ro->body as $key => $value) {
            $view .= "<{$key}>" . (string) $value . "</{$key}>";
        }

        $ro->view = $view;

        return $view;
    }
}
