<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractUri;
use BEAR\Resource\Uri;

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
    public function __invoke(AbstractUri $uri): string
    {
        $query = $uri->query;
        ksort($query);

        // Ensure all forward slashes are replaced, including the leading slash
        $path = str_replace('/', '_', $uri->path);

        return sprintf('%s_%s', $path, http_build_query($query));
    }

    /**
     * {@inheritDoc}
     */
    public function fromAssoc(string $uriTemplate, array $assoc): string
    {
        $surrogateKeys = [];
        foreach ($assoc as $item) {
            $surrogateKeys[] = ($this)(new Uri(uri_template($uriTemplate, $item)));
        }

        return implode(' ', $surrogateKeys);
    }
}
