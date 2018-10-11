<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\AbstractCacheControl;
use Doctrine\Common\Annotations\Reader;
use FakeVendor\HelloWorld\Resource\App\HttpCacheControl;
use Ray\Aop\MethodInterceptor;
use Ray\Aop\MethodInvocation;

class HttpCacheInterceptor implements MethodInterceptor
{
    /**
     * @var Reader
     */
    private $reader;

    public function __construct(
        Reader $annotationReader
    ) {
        $this->reader = $annotationReader;
    }

    /**
     * {@inheritdoc}
     */
    public function invoke(MethodInvocation $invocation)
    {
        $cacheControl = $this->reader->getClassAnnotation($invocation->getMethod()->getDeclaringClass(), AbstractCacheControl::class);
        $ro = $invocation->proceed();
        if ($ro->code === 200 && $cacheControl instanceof AbstractCacheControl) {
            $ro->headers['Cache-Control'] = (string) $cacheControl;
        }

        return $ro;
    }
}
