<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;

use function preg_replace_callback;

/**
 * Donut cache resource state
 */
final class ResourceStatic
{
    /** @var string */
    private $template;

    private const URI_REGEX = '/\[le:(.+)\]/';

    public function __construct(string $template)
    {
        $this->template = $template;
    }

    public function refresh(ResourceInterface $resource, ResourceObject $ro): ResourceState
    {
        $refreshView =  preg_replace_callback(self::URI_REGEX, static function (array $matches) use ($resource) {
            $uri = $matches[1];
            $ro = $resource->get($uri);

            return (string) $ro;
        }, $this->template);

        return ResourceState::create($ro, [], $refreshView);
    }
}
