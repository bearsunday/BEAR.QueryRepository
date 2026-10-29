<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepositoryGraph\Cli;
use BEAR\QueryRepositoryGraph\UriConverter;
use JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

use function array_column;
use function array_map;
use function dirname;
use function explode;
use function fopen;
use function is_array;
use function json_decode;
use function json_encode;
use function rewind;
use function stream_get_contents;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * The declared cache graph of the fake application, as `qr-graph` prints it
 */
class QrGraphTest extends TestCase
{
    private const DIR = 'tests/Fake/fake-app/src/Resource';

    /** @var array<string, array<string, mixed>> */
    private static array $nodes = [];

    /** @var list<array<string, mixed>> */
    private static array $skipped = [];

    public static function setUpBeforeClass(): void
    {
        [$code, $out, $err] = self::qrGraph(['dump', '--dir=' . self::DIR]);
        self::assertSame(0, $code, $err);
        $lines = self::jsonl($out);
        self::assertSame(['version' => 1, 'namespaces' => ['self' => 'FakeVendor\HelloWorld'], 'dir' => self::DIR], $lines[0]['meta']);
        foreach ($lines as $i => $line) {
            if ($i === 0) {
                continue;
            }

            /** @var string $uri */
            $uri = $line['uri'];
            self::$nodes[$uri] = $line;
        }

        self::$skipped = self::jsonl($err);
    }

    public function testUriConverterIsTheInverseOfTheResourceNaming(): void
    {
        $converter = new UriConverter();
        $this->assertSame(
            ['namespace' => 'FakeVendor\HelloWorld', 'uri' => 'page://self/html/blog-posting'],
            $converter->fromClass('FakeVendor\HelloWorld\Resource\Page\Html\BlogPosting'),
        );
        $this->assertNull($converter->fromClass('FakeVendor\HelloWorld\Module\AppModule'));
        $this->assertSame(['uri' => 'page://self/dep/level-two'], $converter->resolve('/dep/level-two', 'page://self/dep/level-one'));
        $this->assertSame(
            ['uri' => 'app://self/refresh-dest', 'template' => 'app://self/refresh-dest{?id}'],
            $converter->resolve('app://self/refresh-dest{?id}', 'app://self/refresh-src'),
        );
        $this->assertSame(
            ['uri' => 'app://self/user/friend', 'template' => 'app://self/user/friend?user_id={id}'],
            $converter->resolve('app://self/user/friend?user_id={id}', 'app://self/user'),
        );
        $this->assertSame(['uri' => 'app://self/user/{id}'], $converter->resolve('app://self/user/{id}', 'app://self/user'));
    }

    public function testEveryLineMatchesItsSchema(): void
    {
        [, $out] = self::qrGraph(['dump', '--dir=' . self::DIR]);
        foreach (explode("\n", trim($out)) as $line) {
            $this->assertSchema('dump.json', $line);
        }

        [, $out] = self::qrGraph(['impact', 'page://self/dep/diamond-bottom', '--dir=' . self::DIR]);
        foreach (explode("\n", trim($out)) as $line) {
            $this->assertSchema('impact.json', $line);
        }
    }

    public function testEmbedResolvesRelativeSrcAgainstTheDeclaringScheme(): void
    {
        $node = self::$nodes['page://self/dep/level-one'];
        $this->assertSame(['expiry' => 'never', 'expirySecond' => 0, 'expiryAt' => '', 'update' => false, 'type' => 'value'], $node['cacheable']);
        $this->assertSame([['rel' => 'level-two', 'uri' => 'page://self/dep/level-two']], self::at($node, 'methods', 'onGet', 'embed'));
        $source = $node['source'];
        $this->assertIsString($source);
        $this->assertStringStartsWith('tests/Fake/fake-app/src/Resource/Page/Dep/LevelOne.php:', $source);
    }

    public function testAFileTheAutoloaderNeverLoadsIsSkippedNotRead(): void
    {
        // App/Dep/LevelOne.php declares the Page\Dep\LevelOne class, which autoloads from Page/Dep.
        $this->assertContains('tests/Fake/fake-app/src/Resource/App/Dep/LevelOne.php', array_map(
            static fn (array $skipped): mixed => $skipped['skipped'],
            self::$skipped,
        ));
    }

    public function testWriteMethodsListImplicitAndDeclaredCommands(): void
    {
        $user = self::$nodes['app://self/user'];
        $this->assertSame([
            ['uri' => 'app://self/user', 'implicit' => 'Cacheable'],
            ['uri' => 'app://self/user/profile', 'template' => 'app://self/user/profile?user_id={id}'],
        ], self::at($user, 'methods', 'onPut', 'refresh'));
        $this->assertSame([['uri' => 'app://self/user/friend', 'template' => 'app://self/user/friend?user_id={id}']], self::at($user, 'methods', 'onPut', 'purge'));
        $this->assertSame([['uri' => 'app://self/user', 'implicit' => 'Cacheable']], self::at($user, 'methods', 'onDelete', 'refresh'));
        $this->assertNull(self::at($user, 'methods', 'onGet'), 'a method declaring nothing is left out');

        // Without #[Cacheable] only the declared command runs.
        $this->assertSame(
            [['uri' => 'app://self/refresh-dest', 'template' => 'app://self/refresh-dest{?id}']],
            self::at(self::$nodes['app://self/refresh-src'], 'methods', 'onPut', 'refresh'),
        );
    }

    public function testDonutPageAndDynamicSurrogateKey(): void
    {
        $node = self::$nodes['page://self/html/blog-posting'];
        $this->assertTrue($node['cacheableResponse']);
        $this->assertSame(['surrogate-key'], $node['dynamic']);
        $this->assertSame([['uri' => 'page://self/html/blog-posting', 'implicit' => 'CacheableResponse']], self::at($node, 'methods', 'onDelete', 'refresh'));
        $this->assertArrayNotHasKey('dynamic', self::$nodes['page://self/dep/level-one']);
    }

    public function testHttpCacheIsTheDeclaredCacheControl(): void
    {
        $this->assertSame('private, no-store, no-cache, must-revalidate', self::$nodes['app://self/no-http-cache-control']['httpCache']);
    }

    public function testImpactReachesEachEmbedderOnceByItsShortestPath(): void
    {
        [$code, $out] = self::qrGraph(['impact', 'page://self/dep/diamond-bottom?id=1', '--dir=' . self::DIR]);
        $this->assertSame(0, $code);
        $lines = self::jsonl($out);
        $this->assertSame(
            [
                'page://self/dep/diamond-bottom',
                'page://self/dep/diamond-left',
                'page://self/dep/diamond-right',
                'page://self/dep/tagged-parent',
                'page://self/dep/diamond-top',
            ],
            array_column($lines, 'uri'),
        );
        $this->assertSame(['target', 'embed', 'embed', 'embed', 'embed'], array_column($lines, 'via'));
        $this->assertSame([0, 1, 1, 1, 2], array_column($lines, 'depth'));
        // The top is reached through both sides of the diamond, and listed once.
        $this->assertSame('page://self/dep/diamond-left', $lines[4]['by']);
        $this->assertTrue($lines[4]['cached']);
    }

    public function testImpactOfAWriteMethodStartsFromWhatItInvalidates(): void
    {
        [, $out] = self::qrGraph(['impact', 'app://self/user', '--method=onPut', '--dir=' . self::DIR]);
        $lines = self::jsonl($out);
        $this->assertSame(
            [
                ['uri' => 'app://self/user', 'via' => 'refresh'],
                ['uri' => 'app://self/user/profile', 'via' => 'refresh'],
                ['uri' => 'app://self/user/friend', 'via' => 'purge'],
            ],
            array_map(static fn (array $line): array => ['uri' => $line['uri'], 'via' => $line['via']], $lines),
        );
        // No scanned resource serves app://self/user/friend.
        $this->assertNull($lines[2]['source']);
        $this->assertFalse($lines[2]['cached']);
    }

    public function testDotDrawsDeclaredEdges(): void
    {
        [$code, $out] = self::qrGraph(['dot', '--dir=' . self::DIR]);
        $this->assertSame(0, $code);
        $this->assertStringStartsWith('digraph qr {', $out);
        $this->assertStringContainsString('"page://self/dep/level-one" -> "page://self/dep/level-two" [label="embed level-two"];', $out);
        $this->assertStringContainsString('"app://self/user" -> "app://self/user/friend" [label="onPut purge", style=dashed];', $out);
    }

    public function testUsageErrors(): void
    {
        [$code, , $err] = self::qrGraph(['impact', '--dir=' . self::DIR]);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('Usage:', $err);

        [$code, , $err] = self::qrGraph(['dump', '--dir=no/such/dir']);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('No such directory: no/such/dir', $err);
    }

    /**
     * The value at a key path of a decoded line, null where the path does not exist
     *
     * @param array<string, mixed> $line
     */
    private static function at(array $line, string ...$path): mixed
    {
        $value = $line;
        foreach ($path as $key) {
            if (! is_array($value) || ! isset($value[$key])) {
                return null;
            }

            $value = $value[$key];
        }

        return $value;
    }

    private function assertSchema(string $schema, string $line): void
    {
        $data = json_decode($line);
        $validator = new Validator();
        $validator->validate($data, (object) ['$ref' => 'file://' . dirname(__DIR__) . '/docs/schemas/graph/' . $schema]);
        $this->assertTrue($validator->isValid(), $line . "\n" . json_encode($validator->getErrors()));
    }

    /**
     * @param list<string> $argv
     *
     * @return array{int, string, string}
     */
    private static function qrGraph(array $argv): array
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        self::assertIsResource($err);
        $code = (new Cli())($argv, $out, $err);
        rewind($out);
        rewind($err);

        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    /** @return list<array<string, mixed>> */
    private static function jsonl(string $text): array
    {
        $lines = [];
        foreach (explode("\n", trim($text)) as $line) {
            if ($line === '') {
                continue;
            }

            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $lines[] = $decoded;
        }

        return $lines;
    }
}
