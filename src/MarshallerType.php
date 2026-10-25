<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

enum MarshallerType: string
{
    case DEFAULT = 'default';

    case DEFLATE = 'deflate';
}
