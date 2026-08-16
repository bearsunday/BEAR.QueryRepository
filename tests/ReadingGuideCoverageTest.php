<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use PHPUnit\Framework\TestCase;

use function assert;
use function basename;
use function file_get_contents;
use function glob;
use function is_array;
use function is_string;
use function json_decode;
use function preg_match;
use function sort;
use function sprintf;
use function str_contains;
use function substr;

/**
 * The reader's guide must keep up with the vocabulary
 *
 * A published word with no explanation is the failure this package exists to avoid, so the guide
 * is held to the same standard as the schemas: adding a context or an outcome word fails here
 * until docs/reading-the-log.md accounts for it.
 */
class ReadingGuideCoverageTest extends TestCase
{
    private string $guide = '';

    protected function setUp(): void
    {
        $this->guide = (string) file_get_contents(__DIR__ . '/../docs/reading-the-log.md');
    }

    public function testEveryContextTypeIsExplained(): void
    {
        $missing = [];
        foreach (glob(__DIR__ . '/../src/Log/Context/*.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);
            if (preg_match("/public const TYPE = '([^']+)'/", $source, $matches) !== 1) {
                continue;
            }

            if (str_contains($this->guide, '`' . $matches[1] . '`')) {
                continue;
            }

            $missing[] = $matches[1];
        }

        sort($missing);
        $this->assertSame([], $missing, 'context types absent from docs/reading-the-log.md');
    }

    public function testEveryOutcomeWordIsExplained(): void
    {
        $missing = [];
        foreach (glob(__DIR__ . '/../docs/schemas/context/*.json') ?: [] as $file) {
            $schema = json_decode((string) file_get_contents($file), true);
            assert(is_array($schema));
            /** @var mixed $properties */
            $properties = $schema['properties'] ?? [];
            if (! is_array($properties)) {
                continue;
            }

            foreach ($properties as $field => $definition) {
                if (! is_array($definition) || ! is_array($definition['enum'] ?? null)) {
                    continue;
                }

                foreach ($definition['enum'] as $word) {
                    if (! is_string($word) || str_contains($this->guide, '`' . $word . '`')) {
                        continue;
                    }

                    $missing[] = sprintf('%s.%s=%s', substr(basename($file), 0, -5), (string) $field, $word);
                }
            }
        }

        sort($missing);
        $this->assertSame([], $missing, 'schema enum values absent from docs/reading-the-log.md');
    }
}
