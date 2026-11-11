<?php

declare(strict_types=1);

/**
 * Snapshot helpers for the demo logs in demo/logs
 *
 * The trees are rendered with RenderConfig snapshot: true, schemaDir: docs/schemas/context,
 * which renders a context schema's "x-volatile" values (durationMs of cache_hit/cache_miss/
 * invalidate) as `[volatile]` inside the tree. The functions below still normalize the same
 * field in the raw "Cache Log JSON" block some demos also print, since stree --snapshot
 * cannot combine with --json (semantic-logger 0.11.1 CHANGELOG) and that rendering never
 * reaches it, plus a value no schema marks volatile at all: the literal ETag/Last-Modified
 * header lines a script prints from $ro->headers, a conditional request's ifNoneMatch, and
 * scene J's Redis-client-specific wording. A Windows PHP_EOL is CRLF; it is folded to LF
 * before any of the above.
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

/**
 * Run one demo in its own process, stderr merged into stdout as a terminal shows it
 *
 * error_reporting is -1 (every level, including E_DEPRECATED) so a php.ini that masks a
 * level cannot hide a real diagnostic from this package; display_errors/html_errors put
 * it on stderr, plain text. normalizeDemoOutput() drops only a diagnostic raised inside
 * vendor/ (its wording and line float with --prefer-lowest); one raised by this package
 * or by a demo stays and fails the comparison.
 */
function runDemo(string $script): string
{
    $process = proc_open(
        [PHP_BINARY, '-d', 'error_reporting=-1', '-d', 'display_errors=stderr', '-d', 'log_errors=0', '-d', 'html_errors=0', $script],
        [1 => ['pipe', 'w'], 2 => ['redirect', 1]],
        $pipes,
        dirname(__DIR__),
    );
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
    $output = (string) str_replace("\r\n", "\n", $output); // a Windows PHP_EOL is CRLF; the stored snapshot is LF
    $output = (string) preg_replace('~^(?:PHP )?(?:Warning|Notice|Deprecated): .* in \S*[/\\\\]vendor[/\\\\]\S* on line \d+\n~m', '', $output);
    $output = normalizeDuration($output);
    $output = normalizePoolError($output);
    $output = (string) preg_replace('/^Last-Modified:.*$/m', 'Last-Modified:(time)', $output);

    return normalizeEtags($output);
}

/**
 * Scene J's pool_error "error"/"exceptionClass" come from whichever Redis client composer
 * installed (ext-redis vs Predis), with a different message and class; masked here since the
 * backend's own wording is not a value this package controls, unlike cache_error's, which a
 * fixture throws on purpose and is left alone
 */
function normalizePoolError(string $output): string
{
    $output = (string) preg_replace('/(pool_error\b[^\n]*?error=).*?( exceptionClass=)\S+/', '${1}(redis client message)${2}(redis client exception)', $output);

    return (string) preg_replace(
        '/("type": "pool_error"[\s\S]*?"error": ")[^"]*(")([\s\S]*?"exceptionClass": ")[^"\\\\]*(?:\\\\.[^"\\\\]*)*(")/',
        '${1}(redis client message)${2}${3}(redis client exception)${4}',
        $output,
    );
}

/** The durationMs that cache_hit, cache_miss and invalidate record, still a real number in the raw JSON block */
function normalizeDuration(string $output): string
{
    return (string) preg_replace('/(durationMs"?[:=] ?)[0-9.]+/', '${1}0', $output);
}

/**
 * Number the hash of each "locator.hash" ETag etag-1, etag-2, ... in order of first appearance
 *
 * The locator comes from the URI alone and is left as it is. An ETag appears in the literal
 * "ETag:" header line a demo prints from $ro->headers, and in a conditional request's
 * ifNoneMatch, which no schema marks volatile since it is the client's own validator.
 * Numbering keeps which responses share one.
 */
function normalizeEtags(string $output): string
{
    $names = [];

    return (string) preg_replace_callback(
        '/((?:etag|ifNoneMatch)"?[:=] ?\\\\?"\\\\?"?)([A-Za-z0-9_-]{22}\.)(\d+)/i',
        static function (array $match) use (&$names): string {
            [, $before, $locator, $hash] = $match;
            $names[$locator . $hash] ??= 'etag-' . (count($names) + 1);

            return $before . $locator . $names[$locator . $hash];
        },
        $output,
    );
}
