<?php

namespace BEAR\QueryRepository;

use FakeVendor\HelloWorld\Resource\App\User;

class MobileEtagSetterTest extends \PHPUnit_Framework_TestCase
{
    const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 6_0 like Mac OS X) AppleWebKit/536.26 (KHTML, like Gecko) Version/6.0 Mobile/10A403 Safari/8536.25';

    const IPAD = 'Mozilla/5.0 (iPad; CPU OS 6_0 like Mac OS X) AppleWebKit/536.26 (KHTML, like Gecko) Version/6.0 Mobile/10A403 Safari/8536.25';

    /**
     * @var FakeMobileEtagSetter
     */
    private $etagSetter;

    private $time;

    private $obj;

    public function setUp()
    {
        parent::setUp();
        $this->obj = new User;
        $this->etagSetter = new FakeMobileEtagSetter;
        $currentTime = time();
        $this->time = gmdate("D, d M Y H:i:s", $currentTime) . ' GMT';
    }

    public function testMobile()
    {
        $_SERVER['HTTP_USER_AGENT'] = self::IPHONE;
        $this->etagSetter->__invoke($this->obj, $this->time);
        $expected = 'mobile';
        $this->assertSame($expected, FakeMobileEtagSetter::$device);
    }

    public function testTablet()
    {
        $_SERVER['HTTP_USER_AGENT'] = self::IPAD;
        $this->etagSetter->__invoke($this->obj, $this->time);
        $expected = 'pc';
        $this->assertSame($expected, FakeMobileEtagSetter::$device);
    }

    public function testPc()
    {
        unset($_SERVER['HTTP_USER_AGENT']);
        $this->etagSetter->__invoke($this->obj, $this->time);
        $expected = 'pc';
        $this->assertSame($expected, FakeMobileEtagSetter::$device);
    }
}
