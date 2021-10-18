<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractUri;

interface UriTagInterface
{
    /**
     * Create surrogate key from URI
     */
    public function __invoke(AbstractUri $uri): string;

    /**
     * Create space separated surrogate keys list from assoc
     *
     * @param list<array<string, mixed>> $assoc
     */
    public function fromAssoc(string $uriTemplate, array $assoc): string;
}
