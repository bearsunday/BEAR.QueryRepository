<?php
/**
 * This file is part of the BEAR.QueryRepository package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */
namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;

class FakeMobileEtagSetter extends MobileEtagSetter
{
    static $device;

    public function __invoke(ResourceObject $resourceObject, $time = null)
    {
        self::$device =  $this->getDevice();
        return parent::__invoke($resourceObject, $time);
    }
}
