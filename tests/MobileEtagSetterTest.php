<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\HttpCache;
use FakeVendor\HelloWorld\Resource\App\User;
use PHPUnit\Framework\TestCase;

use function time;

class MobileEtagSetterTest extends TestCase
{
    public const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 6_0 like Mac OS X) AppleWebKit/536.26 (KHTML, like Gecko) Version/6.0 Mobile/10A403 Safari/8536.25';

    public const IPAD = 'Mozilla/5.0 (iPad; CPU OS 6_0 like Mac OS X) AppleWebKit/536.26 (KHTML, like Gecko) Version/6.0 Mobile/10A403 Safari/8536.25';

    private FakeMobileEtagSetter $etagSetter;
    private int $time;
    private User $obj;

    protected function setUp(): void
    {
        parent::setUp();

        $this->obj = new User();
        $this->etagSetter = new FakeMobileEtagSetter(new MobileEtagSetter());
        $this->time = time();
    }

    public function testMobile(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = self::IPHONE;
        ($this->etagSetter)($this->obj, $this->time, new HttpCache());
        $expected = 'mobile';
        $this->assertSame($expected, FakeMobileEtagSetter::$device);
    }

    public function testTablet(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = self::IPAD;
        ($this->etagSetter)($this->obj, $this->time);
        $expected = 'pc';
        $this->assertSame($expected, FakeMobileEtagSetter::$device);
    }

    public function testPc(): void
    {
        unset($_SERVER['HTTP_USER_AGENT']);
        ($this->etagSetter)($this->obj, $this->time);
        $expected = 'pc';
        $this->assertSame($expected, FakeMobileEtagSetter::$device);
    }

    public function testStatusNotOk(): void
    {
        // A validator on a non-200 lets ConditionalResponse::isModified() answer 304 in
        // place of that response, whatever device the tag was built for.
        $this->obj->code = 301;
        ($this->etagSetter)($this->obj, $this->time);

        $this->assertArrayNotHasKey(Header::ETAG, $this->obj->headers);
    }

    public function testModule(): void
    {
        $this->assertInstanceOf(MobileEtagModule::class, new MobileEtagModule());
    }
}
