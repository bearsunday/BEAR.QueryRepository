<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractRequest;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;

use function array_key_exists;
use function assert;
use function is_iterable;
use function preg_replace_callback;

/**
 * Donut cache resource state
 */
final class ResourceDonut
{
    public const FOMRAT = '[le:%s]';

    /** @var string */
    private $template;

    /**
     * @var ?int
     * @readonly
     */
    public $ttl;

    /**
     * @var bool
     * @readonly
     */
    public $isCacheble;

    private const URI_REGEX = '/\[le:(.+)\]/';

    public function __construct(string $template, ?int $ttl, bool $isCacheble)
    {
        $this->template = $template;
        $this->ttl = $ttl;
        $this->isCacheble = $isCacheble;
    }

    public function refresh(ResourceInterface $resource, ResourceObject $ro): ResourceObject
    {
        $etags = new SurrogateKeys($ro->uri);
        $refreshView =  preg_replace_callback(self::URI_REGEX, static function (array $matches) use ($resource, $etags): string {
            $uri = (string) $matches[1];
            $ro = $resource->get($uri);
            $ro->toString();
            if (array_key_exists(Header::ETAG, $ro->headers)) {
                $etags->addTag($ro);
            }

            return (string) $ro->view;
        }, $this->template);

        $etags->setSurrogateHeader($ro);
        $ro->view = $refreshView;

        return $ro;
    }

    public function render(ResourceObject $ro, DonutRenderer $storage): ResourceObject
    {
        $view = $storage->render($this->template);
        $ro->view = $view;

        return $ro;
    }

    public static function create(ResourceObject $ro, DonutRenderer $storage, SurrogateKeys $etags, ?int $ttl, bool $isCacheble): self
    {
        assert(is_iterable($ro->body));
        /** @var mixed $maybeRequest */
        foreach ($ro->body as &$maybeRequest) {
            if ($maybeRequest instanceof AbstractRequest) {
                $maybeRequest = new DonutRequest($maybeRequest, $storage, $etags);
            }
        }

        unset($maybeRequest);
        $donutTemplate = (string) $ro;

        return new self($donutTemplate, $ttl, $isCacheble);
    }
}
