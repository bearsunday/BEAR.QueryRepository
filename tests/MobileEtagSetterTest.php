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

    /** @var FakeMobileEtagSetter */
    private $etagSetter;

    /** @var int */
    private $time;

    /** @var User */
    private $obj;

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
}
