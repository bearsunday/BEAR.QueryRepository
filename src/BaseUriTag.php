<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;
use Override;
use ReflectionMethod;

use function ltrim;
use function method_exists;
use function preg_replace;
use function strtolower;

/**
 * {@inheritDoc}
 *
 * @internal
 *
 * Drops every default-valued onGet() parameter, supplied or not (MatchQuery keeps the supplied ones);
 * required parameters stay: `onGet(int $userId, int $page = 1)` maps `?userId=1&page=2` to `?userId=1`.
 * A required parameter keeps the key the request spelled, `userId` or the `user_id` BEAR.Resource also binds.
 */
final class BaseUriTag implements BaseUriTagInterface
{
    /**
     * Keeps the group tag distinct from a resource's own UriTag, which cleanUpBeforeWrite() invalidates.
     *
     * @see QueryRepository::GROUP_TAG_PREFIX
     */
    private const GROUP_TAG_PREFIX = 'grp-';

    public function __construct(private readonly UriTagInterface $uriTag = new UriTag())
    {
    }

    #[Override]
    public function __invoke(ResourceObject $ro): string
    {
        if (! method_exists($ro, 'onGet')) {
            return self::GROUP_TAG_PREFIX . ($this->uriTag)($ro->uri);
        }

        $query = $ro->uri->query;
        $baseQuery = [];
        foreach ((new ReflectionMethod($ro, 'onGet'))->getParameters() as $parameter) {
            if ($parameter->isDefaultValueAvailable()) {
                continue;
            }

            $key = isset($query[$parameter->name]) ? $parameter->name : self::snakeName($parameter->name);
            if (! isset($query[$key])) {
                continue;
            }

            /** @psalm-suppress MixedAssignment */
            $baseQuery[$key] = $query[$key];
        }

        $baseUri = clone $ro->uri;
        $baseUri->query = $baseQuery;

        return self::GROUP_TAG_PREFIX . ($this->uriTag)($baseUri);
    }

    /** The conversion BEAR.Resource applies when it binds a query to a parameter */
    private static function snakeName(string $name): string
    {
        return ltrim(strtolower((string) preg_replace('/[A-Z]/', '_\0', $name)), '_');
    }
}
