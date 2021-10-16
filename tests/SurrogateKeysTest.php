<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;
use BEAR\Resource\Uri;
use PHPUnit\Framework\TestCase;

class SurrogateKeysTest extends TestCase
{
    public function testSetSurrogateHeader(): void
    {
        $uri = new Uri('app://self/foo');
        $etags = new SurrogateKeys($uri);
        $etags->addTag(new class extends ResourceObject{
            /** @var array<string, string> */
            public $headers = [
                Header::ETAG => '1',
                Header::SURROGATE_KEY => 'a b',
            ];
        });
        $etags->addTag(new class extends ResourceObject{
            /** @var array<string, string> */
                public $headers = [
                    Header::ETAG => '2',
                    Header::SURROGATE_KEY => 'c',
                ];
        });
        $ro = new class extends ResourceObject{
        };
        $ro->uri = $uri;
        $etags->setSurrogateHeader($ro);
        $this->assertSame('_foo_ 1 a b 2 c', $ro->headers[Header::SURROGATE_KEY]);
    }

    public function testOnePurgeKey(): void
    {
        $uri = new Uri('app://self/foo');
        $etags = new SurrogateKeys($uri);
        $etags->addTag(new class extends ResourceObject{
        });
        $ro = new class extends ResourceObject{
        };
        $ro->uri = $uri;
        $etags->setSurrogateHeader($ro);
        $this->assertSame('_foo_', $ro->headers[Header::SURROGATE_KEY]);
    }
}
