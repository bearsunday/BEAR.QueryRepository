<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Process\Process;

use function array_diff;
use function array_flip;
use function array_keys;
use function basename;
use function dirname;
use function file_get_contents;
use function glob;
use function implode;
use function is_array;
use function is_bool;
use function is_scalar;
use function is_string;
use function json_decode;
use function preg_split;
use function sort;
use function sprintf;
use function str_starts_with;
use function strpos;
use function substr;
use function trim;

use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;
use const PREG_SPLIT_NO_EMPTY;

/**
 * The demos must keep demonstrating the whole cache-log vocabulary
 *
 * `demo/*.php` is where a human or an agent learns to read this package's log: the
 * scripts run real cache scenarios and print the session. That only teaches the whole
 * vocabulary while every context type, every schema enum value, every save outcome and
 * every command producer actually appears in some demo — so this test runs the demos
 * and fails when a shape stops being demonstrated (a new context class with no scenario
 * behind it, an enum value that became unreachable, a producer that stopped firing).
 *
 * It also pins that each demo exits 0, which includes each script's own offline
 * validation of its log against docs/schemas/context.
 */
class DemoLogCoverageTest extends TestCase
{
    private const DEMOS = ['run', 'run-donut', 'run-dependency', 'run-degraded'];

    /**
     * Command producers the log documents.
     *
     * `command.source` is a free string (an application may bind its own interceptor),
     * so this is the set of built-in producers, not a closed enum.
     */
    private const COMMAND_PRODUCERS = ['CommandInterceptor', 'DonutCommandInterceptor', 'RefreshInterceptor'];

    /** @var array<string, int> demo name => exit code */
    private static array $exitCodes = [];

    /** @var array<string, array<string, list<scalar>>> context type => field => observed values */
    private static array $observed = [];

    /** @var list<string> every context type seen across all demo sessions */
    private static array $types = [];

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__);
        foreach (self::DEMOS as $demo) {
            $process = new Process([PHP_BINARY, 'demo/' . $demo . '.php'], $root);
            $process->run();
            self::$exitCodes[$demo] = (int) $process->getExitCode();
            foreach (self::sessionsOf($process->getOutput()) as $session) {
                self::collect($session);
            }
        }
    }

    public function testEveryDemoExitsCleanly(): void
    {
        // Each script validates its own flushed log offline and exits non-zero on any
        // violation or logger diagnostic, so a clean exit is also a schema-conformance check.
        foreach (self::DEMOS as $demo) {
            $this->assertSame(0, self::$exitCodes[$demo], sprintf('demo/%s.php exited non-zero', $demo));
        }
    }

    public function testEveryContextTypeIsDemonstrated(): void
    {
        $declared = [];
        foreach ((array) glob(dirname(__DIR__) . '/src/Log/Context/*.php') as $file) {
            /** @var class-string $class */
            $class = 'BEAR\QueryRepository\Log\Context\\' . basename((string) $file, '.php');
            $type = (new ReflectionClass($class))->getConstant('TYPE');
            $declared[] = is_string($type) ? $type : '';
        }

        $missing = array_diff($declared, self::$types);
        $this->assertSame([], self::sorted($missing), 'context classes no demo provokes: ' . implode(', ', $missing));
    }

    public function testEverySchemaEnumValueIsDemonstrated(): void
    {
        $missing = [];
        foreach ((array) glob(dirname(__DIR__) . '/docs/schemas/context/*.json') as $file) {
            /** @var array<string, mixed> $schema */
            $schema = json_decode((string) file_get_contents((string) $file), true, 512, JSON_THROW_ON_ERROR);
            $type = is_string($schema['title'] ?? null) ? $schema['title'] : basename((string) $file, '.json');
            $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
            foreach ($properties as $field => $spec) {
                $values = is_array($spec) && is_array($spec['enum'] ?? null) ? $spec['enum'] : [];
                foreach ($values as $value) {
                    if (! is_scalar($value) || $this->isObserved($type, (string) $field, $value)) {
                        continue;
                    }

                    $missing[] = sprintf('%s.%s=%s', $type, (string) $field, (string) $value);
                }
            }
        }

        $this->assertSame([], $missing, 'schema enum values no demo emits: ' . implode(', ', $missing));
    }

    public function testEverySaveContextShowsBothOutcomes(): void
    {
        // `saved` is the field that separates "the cache accepted this" from a silent
        // non-store, so both outcomes must be visible for every kind of save.
        $saveTypes = [];
        foreach (self::$observed as $type => $fields) {
            if (! str_starts_with($type, 'save_') || ! isset($fields['saved'])) {
                continue;
            }

            $saveTypes[] = $type;
            $this->assertContains(true, $fields['saved'], sprintf('%s never reports a stored entry', $type));
            $this->assertContains(false, $fields['saved'], sprintf('%s never reports a refused entry', $type));
        }

        $this->assertCount(5, $saveTypes, 'expected save_value/save_view/save_etag/save_donut/save_donut_view');
    }

    public function testEveryCommandProducerIsDemonstrated(): void
    {
        $sources = self::$observed['command']['source'] ?? [];
        foreach (self::COMMAND_PRODUCERS as $producer) {
            $this->assertContains($producer, $sources, sprintf('no demo opens a command scope from %s', $producer));
        }
    }

    /**
     * The flushed sessions a demo printed, parsed from its `Cache Log JSON` blocks
     *
     * @return list<array<string, mixed>>
     */
    private static function sessionsOf(string $output): array
    {
        $sessions = [];
        $blocks = (array) preg_split('/^=== Cache Log JSON.*$/m', $output, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($blocks as $block) {
            $json = (string) $block;
            $end = strpos($json, 'Schema validation');
            if ($end === false || ! str_starts_with(trim($json), '{')) {
                continue; // narration, tree render or trailing text, not a log block
            }

            /** @var array<string, mixed> $session */
            $session = json_decode(trim(substr($json, 0, $end)), true, 512, JSON_THROW_ON_ERROR);
            $sessions[] = $session;
        }

        return $sessions;
    }

    /**
     * Record every entry's type and scalar context values, depth-first
     *
     * @param array<array-key, mixed> $node an open scope or a whole session
     */
    private static function collect(array $node): void
    {
        $opens = is_array($node['open'] ?? null) ? $node['open'] : [];
        foreach ($opens as $open) {
            if (! is_array($open)) {
                continue;
            }

            self::record($open);
            $close = $open['close'] ?? null;
            if (is_array($close)) {
                self::record($close);
            }

            self::collect($open);
        }

        $events = is_array($node['events'] ?? null) ? $node['events'] : [];
        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }

            self::record($event);
        }
    }

    /** @param array<array-key, mixed> $entry */
    private static function record(array $entry): void
    {
        $type = $entry['type'] ?? null;
        if (! is_string($type)) {
            return;
        }

        self::$types[] = $type;
        $context = is_array($entry['context'] ?? null) ? $entry['context'] : [];
        foreach ($context as $field => $value) {
            if (! is_scalar($value)) {
                continue;
            }

            self::$observed[$type][(string) $field][] = $value;
        }
    }

    private function isObserved(string $type, string $field, string|int|float|bool $value): bool
    {
        foreach (self::$observed[$type][$field] ?? [] as $observed) {
            if (is_bool($observed) || is_bool($value)) {
                if ($observed === $value) {
                    return true;
                }

                continue;
            }

            if ((string) $observed === (string) $value) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<array-key, string> $values
     *
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        $list = array_keys(array_flip($values));
        sort($list);

        return $list;
    }
}
