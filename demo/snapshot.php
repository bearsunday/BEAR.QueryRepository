<?php

declare(strict_types=1);

/**
 * Snapshot helpers for the demo logs in demo/logs
 *
 * The trees are rendered with RenderConfig snapshot: true, which leaves out the logger's
 * own timings. What this package itself records per run is replaced here, so a
 * difference against the stored log is a change in behaviour.
 */

/** @return array<string, string> demo name => script path */
function demoScripts(): array
{
    $scripts = [];
    foreach ((array) glob(__DIR__ . '/run*.php') as $path) {
        $scripts[basename((string) $path, '.php')] = (string) $path;
    }

    ksort($scripts);

    return $scripts;
}

/** Run one demo in its own process, stderr merged into stdout as a terminal shows it */
function runDemo(string $script): string
{
    $process = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, dirname(__DIR__));
    if (! is_resource($process)) {
        throw new RuntimeException("Cannot run {$script}");
    }

    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        throw new RuntimeException("{$script} exited with {$exitCode}:\n{$output}");
    }

    return normalizeDemoOutput($output);
}

function normalizeDemoOutput(string $output): string
{
    $output = normalizeDuration($output);
    $output = (string) preg_replace('/^Last-Modified:.*$/m', 'Last-Modified:(time)', $output);

    return normalizeEtags($output);
}

/** The durationMs that cache_hit, cache_miss and invalidate record in their context */
function normalizeDuration(string $output): string
{
    return (string) preg_replace('/(durationMs"?[:=] ?)[0-9.]+/', '${1}0', $output);
}

/**
 * Replace each ETag value with etag-1, etag-2, ... in order of first appearance
 *
 * An ETag hashes a view that can hold an embedded child's Last-Modified, so it
 * changes with the clock. Numbering keeps which entries share a validator.
 */
function normalizeEtags(string $output): string
{
    preg_match_all('/(?:etag|ifNoneMatch)"?[:=] ?\\\\?"\\\\?"?(\d+)\\\\?"/i', $output, $matches);
    $names = [];
    foreach ($matches[1] as $etag) {
        $names[$etag] ??= 'etag-' . (count($names) + 1);
    }

    foreach ($names as $etag => $name) {
        $output = (string) preg_replace('/(?<![0-9])' . $etag . '(?![0-9])/', $name, $output);
    }

    return $output;
}
