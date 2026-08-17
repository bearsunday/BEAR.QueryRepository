<?php

declare(strict_types=1);

/**
 * Degraded-Path Demo — the vocabulary the other demos never reach
 *
 * run.php, run-donut.php and run-dependency.php walk happy paths: hits, misses,
 * saves, cascades. The reason this package logs at all is the opposite case — a
 * cache operation that did NOT happen, or happened and failed. Those are exactly
 * the facts an application cannot see from its own responses, so this demo
 * provokes them and shows what gets recorded for each.
 *
 * Sessions (each has its own bindings, hence its own log):
 *
 *   A. Recorded refusals ......... put_skipped (all three reasons), a 4xx
 *                                  command_result with no invalidation, save_view,
 *                                  manual_store / manual_invalidate
 *   B. Cache server down ......... cache_error{read}, request still served live
 *   C. CDN purge outcomes ........ invalidate{cdn: purged} and {cdn: failed},
 *                                  the failure propagating fail-closed
 *   D. Write-refusing pool ....... save_value / save_etag saved=false,
 *                                  manual_store_result{failed}
 *   E. Donut writes refused ...... save_donut / save_donut_view saved=false
 *   F. Invalidation refused ...... invalidate{roPool: failed, etagPool: failed}
 *   G. Other command producers ... command{source: DonutCommandInterceptor} and
 *                                  command{source: RefreshInterceptor}
 *   H. Finite TTL ................ save_* with a real ttl instead of the `never`
 *                                  placeholder, under #[HttpCache]
 *   I. Direct donut write ........ a donut entry with real TTLs, rooted in
 *                                  manual_store{,_result}
 *
 * Together with the other three demos this reaches every context type, every
 * schema enum value and every command producer the package can emit.
 *
 * The tree is rendered compactly (one line per entry — the view
 * `vendor/bin/stree <file>` prints) because what matters here is each entry's
 * outcome fields, not the shape of a deep embed tree. Every session validates
 * itself offline against docs/schemas/context with failOnDiagnostics on: these
 * are domain failures, so the log must record all of them while staying free of
 * logger diagnostics.
 */

use BEAR\QueryRepository\Cdn\AkamaiModule;
use BEAR\QueryRepository\DonutRepositoryInterface;
use BEAR\QueryRepository\Log\PoolErrorLogger;
use BEAR\QueryRepository\FakeErrorCache;
use BEAR\QueryRepository\FakeEtagPoolModule;
use BEAR\QueryRepository\FakeRefusingPool;
use BEAR\QueryRepository\FakeThrowingPurger;
use BEAR\QueryRepository\ModuleFactory;
use BEAR\QueryRepository\PurgerInterface;
use BEAR\QueryRepository\QueryRepositoryInterface;
use BEAR\QueryRepository\ResourceStorageInterface;
use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\RepositoryModule\Annotation\EtagPool;
use BEAR\RepositoryModule\Annotation\ResourceObjectPool;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\Uri;
use Koriym\SemanticLogger\LogJson;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Koriym\SemanticLogger\Stree\RenderConfig;
use Koriym\SemanticLogger\Stree\TreeRenderer;
use Madapaja\TwigModule\TwigModule;
use Psr\Log\LoggerInterface;
use Ray\Di\InjectionPoints;
use Ray\Di\AbstractModule;
use Ray\Di\Injector;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Adapter\RedisTagAwareAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/validate.php';

/** A CDN purger that succeeds and remembers what it purged. */
final class RecordingPurger implements PurgerInterface
{
    /** @var list<string> */
    public array $tags = [];

    public function __invoke(string $tag): void
    {
        $this->tags[] = $tag;
    }
}

echo <<<'SCENARIOS'
=== Degraded-Path Demo ===

The happy paths live in the other three demos. This one provokes the failures and
refusals a cache normally hides, and shows what the log records for each:

A. Recorded refusals
   1. GET app://self/code returns 203        -> put_skipped{error-code, 203} + purge
   2. GET a page that presets its own ETag   -> put_skipped{etag-present}
   3. Donut page, not whole-page cacheable   -> put_donut, then refresh_donut +
                                                put_skipped{not-cacheable}
   4. DELETE that fails validation (400)     -> command_result{400}, NO invalidation
   5. GET a view-type cacheable resource     -> save_view
   6. Direct put()/invalidateTags()          -> manual_store{,_result}, manual_invalidate
B. Cache server down                         -> cache_error{read}, served live anyway
C. CDN purge, both outcomes                  -> invalidate{cdn: purged} / {cdn: failed},
                                                the failure propagating fail-closed
D. Pool refuses writes                       -> save_value/save_etag saved=false,
                                                manual_store_result{failed}
E. Pool refuses writes, donut page           -> save_donut/save_donut_view saved=false
F. Pool refuses invalidation                 -> invalidate{roPool: failed, etagPool: failed}
G. The other command producers               -> command{source: DonutCommandInterceptor},
                                                command{source: RefreshInterceptor}
H. A finite TTL under #[HttpCache]           -> save_* with ttl=10, private Cache-Control
I. Direct putStatic(ttl, sMaxAge)            -> manual_store{,_result} rooting a donut
                                                write with real ttl/sMaxAge

=== Executing... ===

SCENARIOS;

$namespace = 'FakeVendor\HelloWorld';
$templates = dirname(__DIR__) . '/tests/Fake/fake-app/var/templates';

/**
 * Build an injector for one session.
 *
 * Twig is bound only for sessions that store HTML pages; the app:// resources used
 * elsewhere render as JSON, and binding Twig for them would make a missing template
 * look like a cache failure.
 */
$newInjector = static function (AbstractModule|null $override = null, bool $twig = false) use ($namespace, $templates): Injector {
    $module = new FakeEtagPoolModule(ModuleFactory::getInstance($namespace));
    if ($twig) {
        $module->override(new TwigModule([$templates]));
    }

    if ($override instanceof AbstractModule) {
        $module->override($override);
    }

    return new Injector($module, __DIR__ . '/tmp');
};

// Degraded cache paths warn (E_USER_WARNING) and keep serving; the sessions below
// narrate each one, so print a short marker instead of PHP's multi-line warning.
set_error_handler(static function (int $errno, string $message): bool {
    if ($errno === E_USER_WARNING) {
        echo '   (warning) ' . explode(' in ', $message)[0] . PHP_EOL;

        return true;
    }

    return false;
});

/** Print the session as a tree plus its schema-conforming JSON, then validate it offline. */
$report = static function (LogJson $log, string $title): void {
    echo PHP_EOL . "=== Cache Log Tree — {$title} ===" . PHP_EOL;
    echo (new TreeRenderer(new RenderConfig(false, 0.0, 1000, true)))->render($log) . PHP_EOL;
    echo PHP_EOL . "=== Cache Log JSON — {$title} ===" . PHP_EOL;
    echo json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    validateLog($log);
};

/** Bind one pool (resource-object or ETag) to a refusing decorator. */
$refusingModule = static function (bool $refuseSave, bool $refuseInvalidation, bool $bothPools = false): AbstractModule {
    return new class ($refuseSave, $refuseInvalidation, $bothPools) extends AbstractModule {
        public function __construct(
            private readonly bool $refuseSave,
            private readonly bool $refuseInvalidation,
            private readonly bool $bothPools,
        ) {
            parent::__construct();
        }

        protected function configure(): void
        {
            $pool = new FakeRefusingPool(new TagAwareAdapter(new ArrayAdapter()), $this->refuseSave, $this->refuseInvalidation);
            $this->bind(TagAwareAdapterInterface::class)->annotatedWith(ResourceObjectPool::class)->toInstance($pool);
            if (! $this->bothPools) {
                return;
            }

            $etagPool = new FakeRefusingPool(new TagAwareAdapter(new ArrayAdapter()), $this->refuseSave, $this->refuseInvalidation);
            $this->bind(TagAwareAdapterInterface::class)->annotatedWith(EtagPool::class)->toInstance($etagPool);
        }
    };
};

// ---------------------------------------------------------------- A. refusals
$injector = $newInjector(null, twig: true);
$resource = $injector->getInstance(ResourceInterface::class);
$repository = $injector->getInstance(QueryRepositoryInterface::class);
$storage = $injector->getInstance(ResourceStorageInterface::class);
$logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

$code = $resource->get('app://self/code');                  // A1: 203 — purged, not stored
echo sprintf('A1 GET app://self/code -> %d (not stored)', $code->code) . PHP_EOL;

$resource->get('page://self/html/self-etag');               // A2: response brings its own ETag
echo 'A2 GET page://self/html/self-etag -> put skipped (etag-present)' . PHP_EOL;

$resource->get('page://self/html/blog-posting-donut');      // A3: donut template stored
$repository->purge(new Uri('page://self/html/comment'));    //     inner content invalidated
$resource->get('page://self/html/blog-posting-donut');      //     recomposed, page not stored
echo 'A3 GET donut page twice around a purge -> refresh_donut + put_skipped(not-cacheable)' . PHP_EOL;

$failed = $resource->delete('page://self/html/comment');    // A4: 400 — nothing invalidated
echo sprintf('A4 DELETE page://self/html/comment -> %d (no invalidation)', $failed->code) . PHP_EOL;

$view = $resource->get('app://self/view');                  // A5: type: 'view' — save_view
echo 'A5 GET app://self/view -> save_view (rendered view stored)' . PHP_EOL;

// A6: the same resource written directly, outside any AOP scope: the write and its
// outcome are rooted in a manual_store scope, and a direct invalidateTags() in a
// manual_invalidate one — without that rooting both would be bare root-level events.
$stored = $repository->put($view);
$storage->invalidateTags(['_demo_manual_tag_', '_demo_second_tag_']);
echo sprintf('A6 direct put() -> stored=%s, direct invalidateTags() of two tags -> manual scopes', $stored ? 'true' : 'false') . PHP_EOL;

$report($logger->flush(), 'A. recorded refusals');

// ------------------------------------------------------- B. cache layer down
$injector = $newInjector(new class extends AbstractModule {
    protected function configure(): void
    {
        // Every read/write on the resource pool throws, as if the cache server were gone.
        $this->bind(TagAwareAdapterInterface::class)
            ->annotatedWith(ResourceObjectPool::class)
            ->toInstance(new TagAwareAdapter(new FakeErrorCache()));
    }
});
$resource = $injector->getInstance(ResourceInterface::class);
$logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

$ro = $resource->get('app://self/value');
echo sprintf('B  GET app://self/value -> %d served live; the outage is logged, not swallowed', $ro->code) . PHP_EOL;

$report($logger->flush(), 'B. cache layer down');

// --------------------------------------------------------- C. CDN purge outcomes
$purger = new RecordingPurger();
$injector = $newInjector(new class ($purger) extends AbstractModule {
    public function __construct(private readonly RecordingPurger $purger)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->bind(PurgerInterface::class)->toInstance($this->purger);
    }
});
$resource = $injector->getInstance(ResourceInterface::class);
$repository = $injector->getInstance(QueryRepositoryInterface::class);
$logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

$resource->get('app://self/value');
$repository->purge(new Uri('app://self/value'));
echo sprintf('C1 configured purger ran -> cdn=purged, tags purged: %s', implode(', ', $purger->tags)) . PHP_EOL;

$report($logger->flush(), 'C1. CDN purge succeeded');

$injector = $newInjector(new class extends AbstractModule {
    protected function configure(): void
    {
        $this->bind(PurgerInterface::class)->to(FakeThrowingPurger::class);
    }
});
$resource = $injector->getInstance(ResourceInterface::class);
$repository = $injector->getInstance(QueryRepositoryInterface::class);
$logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

// The populating GET already trips the CDN on its pre-write cleanup: the local pools
// are invalidated, the outcome is logged as cdn=failed, and because the write path
// cannot complete the failure is recorded as cache_error{write} too.
$resource->get('app://self/value');
try {
    $repository->purge(new Uri('app://self/value'));
    echo 'C2 unexpected: the purge did not fail' . PHP_EOL;
} catch (RuntimeException $e) {
    // Fail-closed: local pools are invalidated first and the outcome is logged as
    // cdn=failed, then the exception propagates so the write is not reported as done.
    echo sprintf('C2 purge propagated the CDN failure: %s', $e->getMessage()) . PHP_EOL;
}

$report($logger->flush(), 'C2. CDN purge failed (fail-closed)');

// The third CDN answer the log can record: a flavor module renames the headers. Akamai's
// setter answers max-age=31536000 when no sMaxAge was requested and moves the purge keys
// from Surrogate-Key to Edge-Cache-Tag — cdn_headers records both verbatim, so the log
// shows what THIS CDN was told, not what the generic default would have been.
$injector = $newInjector(new class extends AbstractModule {
    protected function configure(): void
    {
        $this->install(new AkamaiModule());
    }
}, twig: true);
$resource = $injector->getInstance(ResourceInterface::class);
$logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

$page = $resource->get('page://self/html/blog-posting');
echo sprintf('C3 Akamai flavor -> cdn_headers: Akamai-Cache-Control=%s, keys in Edge-Cache-Tag', $page->headers['Akamai-Cache-Control'] ?? '(none)') . PHP_EOL;

$report($logger->flush(), 'C3. Akamai CDN headers');

// ------------------------------------------------------- D. pool refuses writes
$injector = $newInjector($refusingModule(refuseSave: true, refuseInvalidation: false));
$resource = $injector->getInstance(ResourceInterface::class);
$repository = $injector->getInstance(QueryRepositoryInterface::class);
$logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

$value = $resource->get('app://self/value');
$resource->get('app://self/view');
echo 'D  GET a value-type and a view-type resource -> save_value / save_view saved=false' . PHP_EOL;
$stored = $repository->put($value);
echo sprintf('D  direct put() against the same pool -> manual_store_result: %s', $stored ? 'stored' : 'failed') . PHP_EOL;

$report($logger->flush(), 'D. write-refusing pool');

// -------------------------------------------- E. pool refuses writes, donut page
$injector = $newInjector($refusingModule(refuseSave: true, refuseInvalidation: false), twig: true);
$resource = $injector->getInstance(ResourceInterface::class);
$logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

$resource->get('page://self/html/blog-posting');
echo 'E  GET donut page -> save_donut / save_donut_view saved=false' . PHP_EOL;

$report($logger->flush(), 'E. donut writes refused');

// -------------------------------------------------- F. pool refuses invalidation
$injector = $newInjector($refusingModule(refuseSave: false, refuseInvalidation: true, bothPools: true));
$storage = $injector->getInstance(ResourceStorageInterface::class);
$logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

$storage->invalidateTags(['_demo_stuck_tag_']);
echo 'F  invalidateTags() on pools that refuse it -> roPool=failed etagPool=failed' . PHP_EOL;

$report($logger->flush(), 'F. invalidation refused');

// ------------------------------------------------------ G. other command producers
$injector = $newInjector(null, twig: true);
$resource = $injector->getInstance(ResourceInterface::class);
$logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

// DonutCommandInterceptor is bound to #[CacheableResponse] writes (the whole-page
// donut kind), so the write below — not the #[DonutCache] page — is what it drives.
$resource->get('page://self/html/blog-posting?id=0');
$resource->delete('page://self/html/blog-posting?id=0');
echo 'G1 DELETE a whole-page donut resource -> command{source: DonutCommandInterceptor}' . PHP_EOL;

$report($logger->flush(), 'G1. donut command');

$injector = $newInjector();
$resource = $injector->getInstance(ResourceInterface::class);
$logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

// RefreshSrc is NOT #[Cacheable]: its #[Refresh] is driven by RefreshInterceptor,
// the third command producer, which purges and re-populates the annotated target.
$resource->put('app://self/refresh-src', ['id' => 1]);
echo 'G2 PUT a non-cacheable #[Refresh] resource -> command{source: RefreshInterceptor}' . PHP_EOL;

$report($logger->flush(), 'G2. refresh command');

$injector = $newInjector();
$resource = $injector->getInstance(ResourceInterface::class);
$repository = $injector->getInstance(QueryRepositoryInterface::class);
$logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

// PurgeSrc is NOT #[Cacheable] and carries two #[Purge] attributes: purge-only commands,
// no re-population. The command scope records the annotations verbatim - the only
// producer path where Annotation\Purge appears - and level-two's purge cascades to the
// level-one page that embeds it.
$resource->get('page://self/dep/level-one'); // caches level-one -> level-two -> level-three
$logger->flush(); // drain the GET session: this scenario is about the purging write
$resource->put('page://self/dep/purge-src', ['id' => '1']);
$levelTwoGone = $repository->get(new Uri('page://self/dep/level-two')) === null;
echo sprintf('G3 PUT a #[Purge]-only resource -> command.annotations lists Annotation\Purge x2, level-two purged: %s', $levelTwoGone ? 'true' : 'false') . PHP_EOL;

$report($logger->flush(), 'G3. purge-only command');

// ---------------------------------------------------------------- H. finite TTL
$injector = $newInjector();
$resource = $injector->getInstance(ResourceInterface::class);
$logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

$ro = $resource->get('app://self/http-cache-control-with-cacheable');
echo sprintf(
    'H  GET a #[Cacheable(expirySecond: 10), HttpCache(isPrivate: true)] resource -> Cache-Control: %s',
    $ro->headers['Cache-Control'] ?? '(none)',
) . PHP_EOL;
echo '   the save events carry ttl=10, not the 31536000 `never` placeholder' . PHP_EOL;

$report($logger->flush(), 'H. finite TTL');

// ------------------------------------------------- I. donut writes with real TTLs
// The AOP path always calls putStatic($ro, null, null), so a donut entry with a real
// ttl/sMaxAge only ever comes from an application calling DonutRepositoryInterface
// itself. Two things the log shows here that no other session does: the two TTLs of a
// donut entry (template vs rendered view, the latter taking the sMaxAge), and a donut
// write rooted as manual_store — a direct putStatic()/putDonut() is marked
// application-initiated like a direct put()/purge()/invalidateTags(), so the whole
// write, its own pre-write cleanup invalidation included, sits in one scope.
$injector = $newInjector(null, twig: true);
$resource = $injector->getInstance(ResourceInterface::class);
$donutRepository = $injector->getInstance(DonutRepositoryInterface::class);
$logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

$page = $resource->get('page://self/html/blog-posting?id=0');
$logger->flush(); // drain the GET session: this scenario is about the direct write
$donutRepository->putStatic($page, ttl: 60, sMaxAge: 30);
echo 'I  direct putStatic(ttl: 60, sMaxAge: 30) -> put_donut{ttl: 60, sMaxAge: 30}:' . PHP_EOL;
echo '   the template entry keeps ttl=60 while the rendered view and its ETag take ttl=30,' . PHP_EOL;
echo '   and the whole write is rooted in manual_store{,_result} — cleanup invalidate included' . PHP_EOL;

$report($logger->flush(), 'I. donut write through the repository API');

// ------------------------------------------- J. the store is down, and says so
// symfony/cache adapters do not throw: a store that cannot be reached answers a read as a miss
// and a write as false, so session B's outage - an adapter that throws - is not what production
// looks like. A real RedisAdapter pointed at a closed port is, and what makes it visible is the
// PSR-3 logger the pool reports to.
$injector = $newInjector(new class extends AbstractModule {
    protected function configure(): void
    {
        $this->bind(LoggerInterface::class)->annotatedWith('poolError')->to(PoolErrorLogger::class);
        $this->bind(TagAwareAdapterInterface::class)
            ->annotatedWith(ResourceObjectPool::class)
            ->toConstructor(
                RedisTagAwareAdapter::class,
                ['redis' => 'deadRedis'],
                (new InjectionPoints())->addMethod('setLogger', 'poolError'),
            );
        $this->bind()->annotatedWith('deadRedis')->toInstance(RedisAdapter::createConnection('redis://127.0.0.1:1'));
    }
});
$resource = $injector->getInstance(ResourceInterface::class);
$logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

$ro = $resource->get('app://self/value');
echo sprintf('J  GET app://self/value -> %d served live while the store is unreachable:', $ro->code) . PHP_EOL;
echo '   pool_error{read} for the lookup and pool_error{write} for the store, with the' . PHP_EOL;
echo '   backend\'s own message - without them the miss reads exactly like a cold one' . PHP_EOL;

$report($logger->flush(), 'J. the store is down and says so');
