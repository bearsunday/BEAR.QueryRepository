<?php

declare(strict_types=1);

/**
 * Rerun every demo and overwrite its snapshot in demo/logs
 *
 * Review the diff before committing: it is the change in behaviour.
 */

require __DIR__ . '/snapshot.php';

foreach (demoScripts() as $name => $script) {
    file_put_contents(__DIR__ . "/logs/{$name}.log", runDemo($script));
    echo "demo/logs/{$name}.log" . PHP_EOL;
}
