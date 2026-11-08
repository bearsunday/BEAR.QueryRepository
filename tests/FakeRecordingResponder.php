<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;
use BEAR\Resource\TransferInterface;
use Override;

/** Records the headers a transfer would send */
final class FakeRecordingResponder implements TransferInterface
{
    /** @var array<string, string> */
    public array $headers = [];

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function __invoke(ResourceObject $ro, array $server): void
    {
        $this->headers = $ro->headers;
    }
}
