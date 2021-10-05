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
            /** @var array<string, string> */
            public $headers = [
                Header::ETAG => '1',
                Header::PURGE_KEYS => 'a b',
            ];
        });
        $etags->addTag(new class extends ResourceObject{
            /** @var array<string, string> */
                public $headers = [
                    Header::ETAG => '2',
                    Header::PURGE_KEYS => 'c',
                ];
        });
        $ro = new class extends ResourceObject{
        };
        $etags->setSurrogateHeader($ro);
        $this->assertSame('1 a b 2 c', $ro->headers[Header::PURGE_KEYS]);
    }

    public function testNoEtag(): void
    {
        $etags = new SurrogateKeys();
        $etags->addTag(new class extends ResourceObject{
        });
        $ro = new class extends ResourceObject{
        };
        $etags->setSurrogateHeader($ro);
        $this->assertArrayNotHasKey(Header::PURGE_KEYS, $ro->headers);
    }
}
