<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\AbstractCacheControl;
use BEAR\Resource\ResourceObject;
use Ray\Aop\MethodInterceptor;
use Ray\Aop\MethodInvocation;

use function assert;

class HttpCacheInterceptor implements MethodInterceptor
{
    /**
     * {@inheritdoc}
     */
    public function invoke(MethodInvocation $invocation)
    {
        $cacheControl = $invocation->getMethod()->getDeclaringClass()->getAnnotation(AbstractCacheControl::class);
        $ro = $invocation->proceed();
        assert($ro instanceof ResourceObject);
        if ($ro->code === 200 && $cacheControl instanceof AbstractCacheControl) {
            $ro->headers['Cache-Control'] = (string) $cacheControl;
        }

        return $ro;
    }
}
