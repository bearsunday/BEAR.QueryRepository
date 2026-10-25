<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use ArrayObject;
use BEAR\Resource\ResourceObject;
use FakeVendor\HelloWorld\Resource\Page\Html\CounterDonut;
use FakeVendor\HelloWorld\Resource\Page\Html\SelfLinkOnlyDonut;
use PHPUnit\Framework\TestCase;
use Ray\Aop\MethodInvocation;
use Ray\Aop\ReflectionMethod;

use function restore_error_handler;
use function set_error_handler;
use function sprintf;
use function str_contains;

use const E_USER_WARNING;

/**
 * Exercises EmbedOnceInterceptor's own fallback - the one path the install-order test
 * cannot reach, because Ray.Aop's onion ordering makes the swap genuinely always run
 * after EmbedInterceptor (see EmbedOnceInterceptor's docblock). A fake MethodInvocation
 * whose body is still `null` - the real state `EmbedInterceptor::prepareBody()` has not
 * yet turned into `[]` - is the only way to reach it: it stands in for a different AOP
 * container, or a future Ray.Aop release, breaking that guarantee.
 */
class EmbedOnceInterceptorWarningTest extends TestCase
{
    public function testWarnsAndLeavesTheBodyUntouchedWhenItRunsBeforeEmbedInterceptor(): void
    {
        $ro = new CounterDonut();
        // The genuine too-early state: EmbedInterceptor::prepareBody() is what turns a
        // fresh ResourceObject's null body into [] before placing 'counter' into it -
        // if this interceptor runs first, body is still null, not an array missing one key.
        $ro->body = null;

        $invocation = new class ($ro) implements MethodInvocation {
            public function __construct(private readonly ResourceObject $ro)
            {
            }

            public function proceed(): mixed
            {
                return $this->ro;
            }

            public function getThis(): ResourceObject
            {
                return $this->ro;
            }

            public function getArguments(): ArrayObject
            {
                return new ArrayObject();
            }

            public function getNamedArguments(): ArrayObject
            {
                return new ArrayObject();
            }

            public function getMethod(): ReflectionMethod
            {
                return new ReflectionMethod(CounterDonut::class, 'onGet');
            }
        };

        $warnings = [];
        set_error_handler(static function (int $errno, string $message) use (&$warnings): bool {
            if ($errno === E_USER_WARNING) {
                $warnings[] = $message;
            }

            return true;
        });
        try {
            $interceptor = new EmbedOnceInterceptor(new DonutChildExecutions());
            $result = $interceptor->invoke($invocation);
        } finally {
            restore_error_handler();
        }

        $this->assertSame($ro, $result, 'proceed() still runs - the request is served, just without once-only semantics');
        $this->assertNull($ro->body, 'nothing is swapped (or even initialized) when this runs before EmbedInterceptor');
        $this->assertCount(1, $warnings);
        $this->assertTrue(
            str_contains($warnings[0], 'counter') && str_contains($warnings[0], 'EmbedOnceInterceptor ran before EmbedInterceptor'),
            sprintf('unexpected warning message: %s', $warnings[0]),
        );
    }

    public function testSelfLinkOnlyEmbedLeavesANullBodyAlone(): void
    {
        // _self never reaches $ro->body (EmbedInterceptor::linkSelf()), so
        // declaredEmbedRels() is empty for this class and the declared-rel loop never
        // runs - the one real shape that reaches swap()'s is_array($body) fallback
        // instead, with body still null if nothing else has touched it yet.
        $ro = new SelfLinkOnlyDonut();
        $ro->body = null;

        $invocation = new class ($ro) implements MethodInvocation {
            public function __construct(private readonly ResourceObject $ro)
            {
            }

            public function proceed(): mixed
            {
                return $this->ro;
            }

            public function getThis(): ResourceObject
            {
                return $this->ro;
            }

            public function getArguments(): ArrayObject
            {
                return new ArrayObject();
            }

            public function getNamedArguments(): ArrayObject
            {
                return new ArrayObject();
            }

            public function getMethod(): ReflectionMethod
            {
                return new ReflectionMethod(SelfLinkOnlyDonut::class, 'onGet');
            }
        };

        $warnings = [];
        set_error_handler(static function (int $errno, string $message) use (&$warnings): bool {
            if ($errno === E_USER_WARNING) {
                $warnings[] = $message;
            }

            return true;
        });
        try {
            $interceptor = new EmbedOnceInterceptor(new DonutChildExecutions());
            $result = $interceptor->invoke($invocation);
        } finally {
            restore_error_handler();
        }

        $this->assertSame($ro, $result);
        $this->assertNull($ro->body, 'nothing to swap - a self-link embed has no body entry to begin with');
        $this->assertSame([], $warnings, 'no declared rel is ever missing, since _self never counts as one');
    }
}
