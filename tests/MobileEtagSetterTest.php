<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\HttpCache;
use FakeVendor\HelloWorld\Resource\App\User;
use PHPUnit\Framework\TestCase;

class MobileEtagSetterTest extends TestCase
{
    const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 6_0 like Mac OS X) AppleWebKit/536.26 (KHTML, like Gecko) Version/6.0 Mobile/10A403 Safari/8536.25';

    const IPAD = 'Mozilla/5.0 (iPad; CPU OS 6_0 like Mac OS X) AppleWebKit/536.26 (KHTML, like Gecko) Version/6.0 Mobile/10A403 Safari/8536.25';

    /**
     * @var FakeMobileEtagSetter
     */
    private $etagSetter;

    private $time;

    private $obj;

    protected function setUp() : void
    {
        parent::setUp();
        $this->obj = new User;
        $this->etagSetter = new FakeMobileEtagSetter(new MobileEtagSetter);
        $this->time = \time();
    }

    public function testMobile()
    {
        $_SERVER['HTTP_USER_AGENT'] = self::IPHONE;
        ($this->etagSetter)($this->obj, $this->time, new HttpCache);
        $expected = 'mobile';
        $this->assertSame($expected, FakeMobileEtagSetter::$device);
    }

    public function testTablet()
    {
        $_SERVER['HTTP_USER_AGENT'] = self::IPAD;
        ($this->etagSetter)($this->obj, $this->time);
        $expected = 'pc';
        $this->assertSame($expected, FakeMobileEtagSetter::$device);
    }

    public function testPc()
    {
        unset($_SERVER['HTTP_USER_AGENT']);
        ($this->etagSetter)($this->obj, $this->time);
        $expected = 'pc';
        $this->assertSame($expected, FakeMobileEtagSetter::$device);
    }
}
