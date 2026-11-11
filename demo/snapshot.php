<?php

declare(strict_types=1);

/**
 * Snapshot helpers for the demo logs in demo/logs
 *
 * The trees are rendered with RenderConfig snapshot: true, schemaDir: docs/schemas/context,
 * which renders a context schema's "x-volatile" values (durationMs of cache_hit/cache_miss/
 * invalidate; save_etag's etag) as `[volatile]` inside the tree. The functions below still
 * normalize the same fields in the raw "Cache Log JSON" block some demos also print, since
 * stree --snapshot cannot combine with --json (semantic-logger 0.11.1 CHANGELOG) and that
 * rendering never reaches it, plus a value no schema marks volatile at all: the literal
 * ETag/Last-Modified header lines a script prints from $ro->headers, and a conditional
 * request's ifNoneMatch, the client's own validator rather than a value this package made.
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

/** The durationMs that cache_hit, cache_miss and invalidate record, still a real number in the raw JSON block */
function normalizeDuration(string $output): string
{
    return (string) preg_replace('/(durationMs"?[:=] ?)[0-9.]+/', '${1}0', $output);
}

/**
 * Replace each ETag value with etag-1, etag-2, ... in order of first appearance
 *
 * save_etag.etag is already `[volatile]` in the tree; this still covers the same field in
 * the raw JSON block that rendering does not reach, and ifNoneMatch everywhere, which no
 * schema marks volatile since it is the client's own validator. Numbering keeps which
 * entries share one.
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
