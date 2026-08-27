<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractUri;

/**
 * A validator lookup that knows which resource the validator came from
 *
 * A capability beside {@see ResourceStorageInterface} rather than a method on it: the storage
 * contract is released, and an application that implements it should not have to change to keep
 * working. `hasEtag()` answers "is this validator alive anywhere", which is all a pre-routing 304
 * decision can ask; this answers the question RFC 9110 actually poses.
 */
interface ScopedValidatorInterface
{
    /**
     * Is this validator the one issued for this resource?
     *
     * False for a validator issued for another URI, and false for an entry written by a version
     * that stored no URI - after an upgrade each client pays one full response, once.
     */
    public function hasEtagFor(string $etag, AbstractUri $uri): bool;
}
