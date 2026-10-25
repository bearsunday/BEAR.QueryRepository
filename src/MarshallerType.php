<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

/**
 * Marshaller type enumeration
 *
 * Defines available marshaller types for Redis cache adapter.
 */
enum MarshallerType: string
{
    /**
     * Default marshaller with optional igbinary support
     */
    case DEFAULT = 'default';

    /**
     * Deflate marshaller with compression (requires zlib extension)
     */
    case DEFLATE = 'deflate';
}
