<?php

declare(strict_types=1);

/**
 * Write Demo
 *
 * What a write invalidates: #[Refresh] rebuilds the entries it names, #[Purge] only
 * drops them, and the URI template decides which entries those are.
 */

use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\Resource\ResourceInterface;
use Composer\Autoload\ClassLoader;
use FakeVendor\DemoApp\AppModule;
use FakeVendor\DemoApp\TagId;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Ray\Di\Injector;

/** @var ClassLoader $loader */
$loader = require dirname(__DIR__) . '/vendor/autoload.php';
$loader->addPsr4('FakeVendor\DemoApp\\', __DIR__);
require __DIR__ . '/validate.php';

echo <<<'SCENARIOS'
=== Write Demo ===

app://self/tag is #[Cacheable]; every write to app://self/tag-writer bumps its version,
so a rebuilt entry shows the new version and an untouched one the old.

A. GET memberId=1 and member_id=1, then PATCH member_id=1
                                              -> the #[Cacheable] resource's own write
                                                refreshes ?memberId=1 (snake_case query):
                                                a hit with the new body; ?member_id=1 is
                                                only purged, so it misses, then rebuilds
B. PUT    tag-writer id=[a, b]  #[Refresh(uri: 'app://self/tag{?id*}')]
                                              -> one refresh per element: a and b rebuilt
C. DELETE tag-writer id=[a, b]  #[Purge(uri: 'app://self/tag{?id*}')]
                                              -> one purge per element, nothing rebuilt
D. DELETE tag-writer id=[]                   -> an empty list invalidates nothing
E. GET tag with a Stringable id, then DELETE id=[x]
                                              -> the value is part of the key: x is purged
                                                 by its string, y keeps its own entry
F. GET article-summary, which reads app://self/article directly (no #[Embed]) and
   declares #[DependsOn(uri: 'app://self/article?id={id}')]
                                              -> save_value tags it with
                                                 _article_id=1 too; stored, then hits
G. PATCH app://self/article id=1             -> its own write invalidates article-summary
                                                 too, by the tag #[DependsOn] added: miss

=== Executing... ===

SCENARIOS;

$injector = new Injector(new AppModule(), __DIR__ . '/tmp');
$resource = $injector->getInstance(ResourceInterface::class);
$logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

$show = static function (string $label, string $uri, array $query = []) use ($resource): void {
    $body = $resource->get($uri, $query)->body;
    echo sprintf('%s GET %s %s -> %s', $label, $uri, json_encode(array_map(static fn ($value) => $value instanceof Stringable ? "{$value}" : $value, $query)), json_encode($body)) . PHP_EOL;
};
$warmTags = static function (string ...$ids) use ($resource, $logger): void {
    foreach ($ids as $id) {
        $resource->get('app://self/tag', ['id' => $id]);
    }

    $logger->flush(); // the populating GETs are not the scene
};

// ----------------------------------------------------- A. snake_case write query
$resource->get('app://self/member', ['memberId' => 1]);
$resource->get('app://self/member', ['member_id' => 1]);
$logger->flush();
$resource->patch('app://self/member', ['member_id' => 1, 'name' => 'kuma']);
$show('A', 'app://self/member', ['memberId' => 1]);
$show('A', 'app://self/member', ['member_id' => 1]);

reportLog($logger->flush(), 'A. snake_case write query');

// ------------------------------------------------------------- B. #[Refresh] fan-out
$warmTags('a', 'b', 'c');
$resource->put('app://self/tag-writer', ['id' => ['a', 'b']]);
$show('B', 'app://self/tag', ['id' => 'a']);
$show('B', 'app://self/tag', ['id' => 'c']);

reportLog($logger->flush(), 'B. #[Refresh] with a list');

// --------------------------------------------------------------- C. #[Purge] fan-out
$resource->delete('app://self/tag-writer', ['id' => ['a', 'b']]);
$show('C', 'app://self/tag', ['id' => 'a']);

reportLog($logger->flush(), 'C. #[Purge] with a list');

// ------------------------------------------------------------------ D. empty list
$resource->delete('app://self/tag-writer', ['id' => []]);
$show('D', 'app://self/tag', ['id' => 'c']);

reportLog($logger->flush(), 'D. #[Purge] with an empty list');

// ---------------------------------------------------------- E. Stringable query value
$resource->get('app://self/tag', ['id' => new TagId('x')]);
$resource->get('app://self/tag', ['id' => new TagId('y')]);
$resource->delete('app://self/tag-writer', ['id' => ['x']]);
$show('E', 'app://self/tag', ['id' => new TagId('x')]);
$show('E', 'app://self/tag', ['id' => new TagId('y')]);

reportLog($logger->flush(), 'E. Stringable query value');

// ------------------------------------------------- F. #[DependsOn] without an #[Embed]
$resource->get('app://self/article-summary', ['id' => 1]); // miss: save_value adds tag _article_id=1
$show('F', 'app://self/article-summary', ['id' => 1]);      // hit: the stored summary

reportLog($logger->flush(), 'F. #[DependsOn] reads another resource without #[Embed]');

// --------------------------------------- G. a write to the declared URI invalidates it
$resource->patch('app://self/article', ['id' => 1, 'title' => 'Big Update']);
$show('G', 'app://self/article-summary', ['id' => 1]); // miss: rebuilt with the new title

reportLog($logger->flush(), 'G. a write to the #[DependsOn] URI invalidates it');
