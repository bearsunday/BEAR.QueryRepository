<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;
use BEAR\RepositoryModule\Annotation\HttpCache;

class FakeMobileEtagSetter extends MobileEtagSetter
{
    public static $device;

    public function __invoke(ResourceObject $resourceObject, int $time = null, HttpCache $httpCache = null)
    {
        self::$device = $this->getDevice();

        return parent::__invoke($resourceObject, $time, $httpCache);
    }
}
