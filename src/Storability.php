<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

/**
 * Whether a write may put the response in the shared pool, and whether a CDN may be given a lifetime for it
 *
 * @internal
 */
enum Storability
{
    case Shared;

    /** Stored; a CDN must not be given a lifetime, as CDN-Cache-Control makes it ignore Cache-Control (RFC 9213 §2.2) */
    case DeclaredPrivate;

    /** Not stored, and its CDN-facing headers are stripped */
    case Unstorable;
}
