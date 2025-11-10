<?php

declare(strict_types=1);

array_map('unlink', glob(__DIR__ . '/tests/tmp/*.php')); // @phpstan-ignore-line
