<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;
use BEAR\Resource\Uri;
use ErrorException;
use PHPUnit\Framework\TestCase;

use function restore_error_handler;
use function set_error_handler;
use function unserialize;

class BaseUriTagTest extends TestCase
{
    public function testLegacySerializedTagStillInvokes(): void
    {
        $tag = unserialize("O:31:\"BEAR\\QueryRepository\\BaseUriTag\":1:{s:39:\"\0BEAR\\QueryRepository\\BaseUriTag\0uriTag\";O:27:\"BEAR\\QueryRepository\\UriTag\":0:{}}");
        $this->assertInstanceOf(BaseUriTag::class, $tag);
        $ro = new class extends ResourceObject {
            public function onGet(string $id, int $page = 1): static
            {
                unset($id, $page);

                return $this;
            }
        };
        $ro->uri = new Uri('app://self/item?id=a&page=2');

        $this->assertSame('grp-_item_id=a', $tag($ro));
        $ro = new class extends ResourceObject {
        };
        $ro->uri = new Uri('page://self/item?page=2');
        $this->assertSame('grp-_item_page=2', $tag($ro));
    }

    /**
     * A required parameter bound from outside the query (e.g. #[ResourceParam]) is not part of the URI, so not of
     * the group. Reading it from the query would raise a warning, which an app's error handler may turn into an exception.
     */
    public function testARequiredParameterAbsentFromTheQueryIsLeftOutWithoutAWarning(): void
    {
        $ro = new class extends ResourceObject {
            public function onGet(int $id, string $token, int $page = 1): static
            {
                unset($id, $token, $page);

                return $this;
            }
        };
        $ro->uri = new Uri('app://self/item?id=1&page=2');
        $uriTag = new UriTag();

        set_error_handler(static function (int $errno, string $errstr): never {
            throw new ErrorException($errstr, 0, $errno);
        });
        try {
            $groupTag = (new BaseUriTag($uriTag))($ro);
        } finally {
            restore_error_handler();
        }

        $this->assertSame('grp-' . $uriTag(new Uri('app://self/item?id=1')), $groupTag);
    }

    public function testStringableRequiredParameterKeepsItsStringInTheGroupTag(): void
    {
        $ro = new class extends ResourceObject {
            public function onGet(string $id, int $page = 1): static
            {
                unset($id, $page);

                return $this;
            }
        };
        $ro->uri = new Uri('app://self/item');
        $ro->uri->query = ['id' => new FakeStringableId('a'), 'page' => 2];

        $this->assertSame('grp-_item_id=a', (new BaseUriTag(new UriTag()))($ro));
    }
}
