<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\Resource\ResourceInterface;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

/**
 * What the log says about an entry's intended lifetime.
 *
 * A resolved TTL cannot answer "is this meant to expire?": the `never` preset resolves to a
 * finite number, and an application can rebind `Expiry` to make it any number at all. The
 * declaration is recorded instead, and only the one that decided.
 */
class CachePolicyLogTest extends TestCase
{
    use SemanticLogTreeTrait;

    private ResourceInterface $resource;
    private SemanticLoggerInterface $logger;

    protected function setUp(): void
    {
        $injector = new Injector(new FakeEtagPoolModule(ModuleFactory::getInstance('FakeVendor\HelloWorld')), __DIR__ . '/tmp');
        $this->resource = $injector->getInstance(ResourceInterface::class);
        $this->logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

        parent::setUp();
    }

    public function testAPresetIsRecordedAsDeclaredBesideTheNumberItResolvedTo(): void
    {
        $this->resource->get('app://self/entry', ['id' => 1]); // #[Cacheable(expiry: 'never')]

        $policy = self::eventContextJsonOf($this->flushAndValidate($this->logger), 'cache_policy');

        $this->assertNotNull($policy);
        $this->assertStringContainsString('"expiry":"never"', $policy, 'the word the resource declared');
        $this->assertStringContainsString('"expirySecond":null', $policy, 'seconds did not decide this entry');
        $this->assertStringContainsString('"expiryAt":null', $policy);
        // The number is a backstop, and a default install turns `never` into 365 days - which is
        // why the word has to be in the log: this reads exactly like a deliberate 1-year TTL.
        $this->assertStringContainsString('"resolvedTtl":31536000', $policy);
    }

    public function testAnExplicitSecondCountIsTheDeclarationThatDecided(): void
    {
        $this->resource->get('app://self/control-none'); // #[Cacheable(expirySecond: 60)]

        $policy = self::eventContextJsonOf($this->flushAndValidate($this->logger), 'cache_policy');

        $this->assertNotNull($policy);
        $this->assertStringContainsString('"expirySecond":60', $policy);
        $this->assertStringContainsString('"expiry":null', $policy, 'the preset is not what decided');
        $this->assertStringContainsString('"resolvedTtl":60', $policy);
    }

    public function testAnExpiryFieldInTheBodyOutranksThePreset(): void
    {
        $this->resource->get('app://self/control-expiry'); // #[Cacheable(expiryAt: 'expiry_at')]

        $policy = self::eventContextJsonOf($this->flushAndValidate($this->logger), 'cache_policy');

        $this->assertNotNull($policy);
        $this->assertStringContainsString('"expiryAt":"expiry_at"', $policy, 'the body field that carries the deadline');
        $this->assertStringContainsString('"expiry":null', $policy);
        $this->assertStringContainsString('"expirySecond":null', $policy);
    }

    public function testAResourceWithNoCacheableDeclarationHasNoPolicy(): void
    {
        // The log carries a policy only where a declaration exists to record.
        $this->resource->get('app://self/no-http-cache-control');

        $this->assertNull(self::eventContextJsonOf($this->flushAndValidate($this->logger), 'cache_policy'));
    }
}
