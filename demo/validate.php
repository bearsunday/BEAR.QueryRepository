<?php

declare(strict_types=1);

/**
 * Offline schema validation for the demo cache logs
 *
 * Lets each demo prove that the log it just printed conforms to the published
 * per-context schemas. Validation is fully offline: Koriym\SemanticLogger's
 * SemanticLogValidator maps each context's schemaUrl basename to a local file
 * under docs/schemas/context — no URL is ever fetched.
 */

use Koriym\SemanticLogger\LogJson;
use Koriym\SemanticLogger\SemanticLogValidator;

/**
 * Validate the flushed log against docs/schemas/context and print a one-line verdict
 *
 * The validator echoes one line per context, so its output is buffered and only
 * shown on failure. A violation exits non-zero: a demo whose log does not
 * conform to the schemas it advertises must fail loudly, not print a lie. The
 * same applies to the diagnostics the logger records instead of throwing since
 * koriym/semantic-logger 0.9 (an unclosed scope, a close out of order): a demo
 * that misuses the logging protocol is a broken demo, so failOnDiagnostics is on.
 */
function validateLog(LogJson $log): void
{
    $tree = $log->toArray();
    $file = (string) tempnam(sys_get_temp_dir(), 'slog');
    file_put_contents($file, (string) json_encode($tree, JSON_UNESCAPED_SLASHES));

    $exception = null;
    ob_start();
    try {
        (new SemanticLogValidator())->validate($file, dirname(__DIR__) . '/docs/schemas/context', failOnDiagnostics: true);
    } catch (RuntimeException $e) {
        $exception = $e;
    } finally {
        $details = (string) ob_get_clean();
        unlink($file);
    }

    if ($exception !== null) {
        echo $details; // the validator's per-violation report
        echo 'Schema validation: FAILED (' . $exception->getMessage() . ')' . PHP_EOL;
        exit(1);
    }

    echo sprintf('Schema validation: OK (%d entries)', countLogEntries($tree)) . PHP_EOL;
}

/**
 * Count every entry in the log tree: open scopes, their closes and events, recursively
 *
 * @param array<string, mixed> $node
 */
function countLogEntries(array $node): int
{
    $count = 0;
    $opens = $node['open'] ?? [];
    if (is_array($opens)) {
        foreach ($opens as $child) {
            if (! is_array($child)) {
                continue;
            }

            $count += 1 + countLogEntries($child); // the scope itself + its contents
            $count += isset($child['close']) ? 1 : 0;
        }
    }

    $events = $node['events'] ?? [];

    return $count + (is_array($events) ? count($events) : 0);
}
