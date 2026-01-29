<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use PHPUnit\Framework\TestCase;

class GlobalServerContextTest extends TestCase
{
    private GlobalServerContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = new GlobalServerContext();
    }

    protected function tearDown(): void
    {
        unset($_SERVER['TEST_KEY'], $_SERVER['TEST_STRING'], $_SERVER['TEST_INT']);

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
