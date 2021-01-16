<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\NoHttpCache;
use BEAR\RepositoryModule\Annotation\Purge;
use BEAR\RepositoryModule\Annotation\Refresh;
use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Annotations\Reader;
use Koriym\Attributes\AttributeReader;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class AttributeTest extends TestCase
{
    /** @var Reader */
    protected $reader;

    /**
     * @return array<Reader>
     */
    public function readerProvider() : array
    {
        return [
            [new AttributeReader()],
            [new AnnotationReader()]
        ];
    }

    /**
     * @dataProvider readerProvider
     */
    public function testReadAttributes(Reader $reader) : void
    {
        $class = new ReflectionClass(FakeAttributes::class);
        $cacheable = $reader->getClassAnnotation($class, Cacheable::class);
        $this->assertInstanceOf(Cacheable::class, $cacheable);
        $noHttpCache = $reader->getClassAnnotation($class, NoHttpCache::class);
        $this->assertInstanceOf(NoHttpCache::class, $noHttpCache);
        $noHttpCache = $reader->getClassAnnotation($class, NoHttpCache::class);
        $this->assertInstanceOf(NoHttpCache::class, $noHttpCache);
        $method = new ReflectionMethod(FakeAttributes::class, 'onGet');
        $purge = $reader->getMethodAnnotation($method, Purge::class);
        $this->assertInstanceOf(Purge::class, $purge);
        $refresh = $reader->getMethodAnnotation($method, Refresh::class);
        $this->assertInstanceOf(Refresh::class, $refresh);
    }
}
