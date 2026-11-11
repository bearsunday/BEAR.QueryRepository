<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function demoScripts;
use function dirname;
use function file_get_contents;
use function runDemo;

/** Update the snapshots with `composer demo` */
class DemoSnapshotTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function demoProvider(): array
    {
        require_once dirname(__DIR__) . '/demo/snapshot.php';

        $cases = [];
        foreach (demoScripts() as $name => $script) {
            $cases[$name] = [$script, dirname(__DIR__) . "/demo/logs/{$name}.log"];
        }

        return $cases;
    }

    #[DataProvider('demoProvider')]
    public function testOutputMatchesSnapshot(string $script, string $snapshot): void
    {
        $this->assertSame((string) file_get_contents($snapshot), runDemo($script));
    }
}
