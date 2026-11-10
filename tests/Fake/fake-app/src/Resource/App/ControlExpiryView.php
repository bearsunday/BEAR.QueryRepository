<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;
use DateTime;
use Override;

/** ControlExpiry stored as a view entry */
#[Cacheable(type: 'view', expiryAt: 'expiry_at')]
class ControlExpiryView extends ResourceObject
{
    #[Override]
    public function toString(): string
    {
        return $this->view ??= 'rendered';
    }

    public function onGet(): static
    {
        $this->body['expiry_at'] = (new DateTime('+30 sec'))->format('Y-m-d H:i:s');

        return $this;
    }
}
