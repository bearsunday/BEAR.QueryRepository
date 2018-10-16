<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\AbstractCacheControl;
use Ray\Aop\MethodInterceptor;
use Ray\Aop\MethodInvocation;

class HttpCacheInterceptor implements MethodInterceptor
{
    /**
     * {@inheritdoc}
     */
    public function invoke(MethodInvocation $invocation)
    {
        $cacheControl = $invocation->getMethod()->getDeclaringClass()->getAnnotation(AbstractCacheControl::class);
        $ro = $invocation->proceed();
        if ($ro->code === 200 && $cacheControl instanceof AbstractCacheControl) {
            $ro->headers['Cache-Control'] = (string) $cacheControl;
        }

        return $ro;
    }
}
