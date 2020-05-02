<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

function syslog(int $priority, string $message)
{
    $GLOBALS['BEAR\QueryRepository\syslog'] = [$priority, $message];
}
