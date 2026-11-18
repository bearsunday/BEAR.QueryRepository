<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\Resource\Code;
use BEAR\Resource\ResourceObject;
use BEAR\Resource\Uri;

/** A writer with no cache attribute whose onPut() returns a receipt, not itself */
class ReceiptWriter extends ResourceObject
{
    public function onGet(int $id): static
    {
        $this->body = ['id' => $id];

        return $this;
    }

    public function onPut(int $id): ResourceObject
    {
        $receipt = new Receipt();
        $receipt->uri = new Uri('app://self/receipt');
        $receipt->code = Code::ACCEPTED;
        $receipt->body = ['written' => $id];

        return $receipt;
    }
}
