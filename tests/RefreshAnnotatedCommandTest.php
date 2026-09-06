<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Log\Context\SaveEtagContext;
use BEAR\QueryRepository\Log\Context\SaveValueContext;
use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\Uri;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Override;
use PHPUnit\Framework\TestCase;
use Ray\Di\AbstractModule;
use Ray\Di\Injector;

use function array_filter;
use function array_values;

class RefreshAnnotatedCommandTest extends TestCase
{
    private ResourceInterface $resource;
    private QueryRepositoryInterface $repository;
    private RecordingSemanticLogger $logger;
    private FakeCountingPurger $purger;

    protected function setUp(): void
    {
        $namespace = 'FakeVendor\HelloWorld';
        $this->logger = new RecordingSemanticLogger();
        $this->purger = new FakeCountingPurger();

        $logger = $this->logger;
        $purger = $this->purger;
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance($namespace));
        $module->override(new class ($logger, $purger) extends AbstractModule {
            public function __construct(
                private readonly SemanticLoggerInterface $logger,
                private readonly PurgerInterface $purger,
            ) {
            }

            #[Override]
            protected function configure(): void
            {
                $this->bind(SemanticLoggerInterface::class)->annotatedWith(CacheLog::class)->toInstance($this->logger);
                $this->bind(PurgerInterface::class)->toInstance($this->purger);
            }
        });

        $injector = new Injector($module, __DIR__ . '/tmp');
        $this->resource = $injector->getInstance(ResourceInterface::class);
        $this->repository = $injector->getInstance(QueryRepositoryInterface::class);

        parent::setUp();
    }

    public function testRefreshToCacheableDestinationWritesExactlyOnce(): void
    {
        $this->resource->get('app://self/user/profile', ['user_id' => 1]);
        $this->logger->events = [];
        $this->purger->tags = [];

        $this->resource->put('app://self/user', ['id' => 1, 'name' => 'x', 'age' => 5]);

        $saveValueEvents = self::eventsOfType($this->logger, SaveValueContext::class, 'app://self/user/profile?user_id=1');
        $saveEtagEvents = self::eventsOfType($this->logger, SaveEtagContext::class, 'app://self/user/profile?user_id=1');
        $this->assertCount(1, $saveValueEvents);
        $this->assertCount(1, $saveEtagEvents);

        $profileTagPurges = array_values(array_filter(
            $this->purger->tags,
            static fn (string $tag): bool => $tag === '_user_profile_user_id=1',
        ));
        $this->assertCount(2, $profileTagPurges, 'the explicit purge plus the GET\'s own pre-write cleanup');

        $this->assertNotNull($this->repository->get(new Uri('app://self/user/profile?user_id=1')));
    }

    public function testRefreshToNonCacheableDestinationStillWritesOnce(): void
    {
        $this->resource->put('app://self/refresh-src', ['id' => 1]);

        $saveValueEvents = self::eventsOfType($this->logger, SaveValueContext::class, 'app://self/refresh-dest?id=1');
        $this->assertCount(1, $saveValueEvents);
    }

    /**
     * @param class-string<SaveValueContext|SaveEtagContext> $type
     *
     * @return list<SaveValueContext|SaveEtagContext>
     */
    private static function eventsOfType(RecordingSemanticLogger $logger, string $type, string $uri): array
    {
        $matches = [];
        foreach ($logger->events as $event) {
            if ($event instanceof $type && $event->uri === $uri) {
                $matches[] = $event;
            }
        }

        return $matches;
    }
}
