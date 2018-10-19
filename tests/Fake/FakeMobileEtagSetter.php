<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\HttpCache;
use BEAR\Resource\ResourceObject;

class FakeMobileEtagSetter implements EtagSetterInterface
{
    public static $device;

    /**
     * @var MobileEtagSetter
     */
    private $mobileEtagSetter;

    public function __construct(MobileEtagSetter $mobileEtagSetter)
    {
        $this->mobileEtagSetter = $mobileEtagSetter;
    }

    public function __invoke(ResourceObject $ro, int $time = null, HttpCache $httpCache = null)
    {
        self::$device = $this->getDevice();

        return ($this->mobileEtagSetter)($ro, $time, $httpCache);
    }

    private function getDevice()
    {
        $detect = new \Mobile_Detect;
        $mobileDeviceType = $detect->isMobile() && ! $detect->isTablet() ? 'mobile' : 'pc';

        return $mobileDeviceType;
    }

}
