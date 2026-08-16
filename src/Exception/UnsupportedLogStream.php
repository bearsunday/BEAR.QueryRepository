<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Exception;

/** A log stream target that is neither a filesystem path nor a standard PHP output stream */
final class UnsupportedLogStream extends LogicException
{
}
