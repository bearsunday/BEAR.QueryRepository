<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;
use PHPUnit\Framework\TestCase;

class SurrogateKeysTest extends TestCase
{
    public function testSetSurrogateHeader(): void
    {
        $etags = new SurrogateKeys();
        $etags->addTag(new class extends ResourceObject{
            public $headers = [
                'ETag' => '1',
                CacheDependency::SURROGATE_KEY => 'a b',
            ];
        });
        $etags->addTag(new class extends ResourceObject{
            public $headers = [
                'ETag' => '2',
                CacheDependency::SURROGATE_KEY => 'c',
            ];
        });
        $ro = new class extends ResourceObject{
        };
        $etags->setSurrogateHeader($ro);
        $this->assertSame('1 a b 2 c', $ro->headers[CacheDependency::SURROGATE_KEY]);
    }

    public function testNoEtag(): void
    {
        $etags = new SurrogateKeys();
        $etags->addTag(new class extends ResourceObject{
        });
        $ro = new class extends ResourceObject{
        };
        $etags->setSurrogateHeader($ro);
        $this->assertArrayNotHasKey(CacheDependency::SURROGATE_KEY, $ro->headers);
    }
}
