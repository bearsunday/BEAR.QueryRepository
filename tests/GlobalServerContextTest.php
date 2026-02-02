<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use PHPUnit\Framework\TestCase;

use function array_key_exists;

class GlobalServerContextTest extends TestCase
{
    private GlobalServerContext $context;

    /** @var array<string, mixed> */
    private array $originalServer = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Save original values for keys we'll modify
        foreach (['TEST_KEY', 'TEST_STRING', 'TEST_INT', 'HTTP_USER_AGENT', 'X_VARY'] as $key) {
            if (array_key_exists($key, $_SERVER)) {
                $this->originalServer[$key] = $_SERVER[$key];
            }
        }

        $this->context = new GlobalServerContext();
    }

    protected function tearDown(): void
    {
        // Restore original values or unset if they didn't exist
        foreach (['TEST_KEY', 'TEST_STRING', 'TEST_INT', 'HTTP_USER_AGENT', 'X_VARY'] as $key) {
            if (array_key_exists($key, $this->originalServer)) {
                $_SERVER[$key] = $this->originalServer[$key];
            } else {
                unset($_SERVER[$key]);
            }
        }

        parent::tearDown();
    }

    public function testGetReturnsValue(): void
    {
        $_SERVER['TEST_KEY'] = 'test_value';

        $this->assertSame('test_value', $this->context->get('TEST_KEY'));
    }

    public function testGetReturnsNullForNonExistentKey(): void
    {
        $this->assertNull($this->context->get('NON_EXISTENT_KEY'));
    }

    public function testGetReturnsNullForNonStringValue(): void
    {
        $_SERVER['TEST_INT'] = 123;

        $this->assertNull($this->context->get('TEST_INT'));
    }

    public function testHasReturnsTrueForExistingKey(): void
    {
        $_SERVER['TEST_KEY'] = 'value';

        $this->assertTrue($this->context->has('TEST_KEY'));
    }

    public function testHasReturnsFalseForNonExistentKey(): void
    {
        $this->assertFalse($this->context->has('NON_EXISTENT_KEY'));
    }

    public function testGetHttpUserAgent(): void
    {
        $userAgent = 'Mozilla/5.0 Test';
        $_SERVER['HTTP_USER_AGENT'] = $userAgent;

        $this->assertSame($userAgent, $this->context->get('HTTP_USER_AGENT'));
    }

    public function testGetXVary(): void
    {
        $_SERVER['X_VARY'] = 'val1,val2';

        $this->assertSame('val1,val2', $this->context->get('X_VARY'));
    }
}
