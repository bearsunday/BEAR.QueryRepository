<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;
use Throwable;

/**
 * Decides what a put-time body serialization failure does to the request
 *
 * Returning leaves the response uncached (headers stripped, nothing stored, `put_skipped`
 * logged) without throwing further; throwing fails the request instead.
 */
interface BodySerializationErrorHandlerInterface
{
    public function __invoke(ResourceObject $ro, Throwable $e): void;
}
