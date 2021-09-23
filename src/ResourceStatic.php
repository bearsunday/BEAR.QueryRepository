<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractRequest;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;

use function preg_replace_callback;

/**
 * Donut cache resource state
 */
final class ResourceStatic
{
    public const FOMRAT = '[le:%s]';

    /** @var string */
    private $template;

    private const URI_REGEX = '/\[le:(.+)\]/';

    public function __construct(string $template)
    {
        $this->template = $template;
    }

    public function refresh(ResourceInterface $resource, ResourceObject $ro): ResourceObject
    {
        $refreshView =  preg_replace_callback(self::URI_REGEX, static function (array $matches) use ($resource) {
            $uri = $matches[1];
            $ro = $resource->get($uri);

            return (string) $ro;
        }, $this->template);

        $ro->view = $refreshView;

        return $ro;
    }

    public function render(ResourceObject $ro, DonutRenderer $storage): ResourceObject
    {
        $view = $storage->render($this->template);
        $ro->view = $view;

        return $ro;
    }

    public static function create(ResourceObject $ro, DonutRenderer $storage)
    {
        foreach ($ro->body as &$maybeRequest) {
            if ($maybeRequest instanceof AbstractRequest) {
                $maybeRequest = new DonutRequest($maybeRequest, $storage);
            }
        }

        $donutTemplate = (string) $ro;

        return new self($donutTemplate);
    }
}
