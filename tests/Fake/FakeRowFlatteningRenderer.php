<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\RenderInterface;
use BEAR\Resource\ResourceObject;
use Override;

use function array_map;
use function assert;
use function is_array;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/** Turns the rows of the body into arrays as it renders, as a renderer that normalizes objects may */
final class FakeRowFlatteningRenderer implements RenderInterface
{
    #[Override]
    public function render(ResourceObject $ro): string
    {
        assert(is_array($ro->body) && is_array($ro->body['entries']));
        $ro->body['entries'] = array_map(static fn (object $row): array => (array) $row, $ro->body['entries']);
        $ro->view = json_encode($ro->body, JSON_THROW_ON_ERROR);

        return $ro->view;
    }
}
