<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractUri;
use BEAR\Resource\Uri;
use Override;

use function http_build_query;
use function implode;
use function ksort;
use function sprintf;
use function str_replace;
use function uri_template;

final class UriTag implements UriTagInterface
{
    /**
     * {@inheritDoc}
     */
    #[Override]
    public function __invoke(AbstractUri $uri): string
    {
        $query = $uri->query;
        ksort($query);

        $uriKey = sprintf('%s_%s', $uri->path, http_build_query($query));
        // Sanitize the URI key by replacing special characters (/, ?, &) with underscores
        // to ensure it is a valid surrogate key and make it enable with Symfony cache adapter
        return str_replace(['/', '?', '&'], '_', $uriKey);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function fromAssoc(string $uriTemplate, array $assoc): string
    {
        $surrogateKeys = [];
        foreach ($assoc as $item) {
            $surrogateKeys[] = ($this)(new Uri(uri_template($uriTemplate, $item)));
        }

        return implode(' ', $surrogateKeys);
    }
}
