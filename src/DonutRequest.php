<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractRequest;
use Stringable;

use function sprintf;

final class DonutRequest implements Stringable
{
    public function __construct(
        private AbstractRequest $request,
        private DonutRendererInterface $donutStorage,
        private SurrogateKeys $etags,
    ) {
    }

    public function __toString(): string
    {
        $view = (string) ($this->request)();
        $uri =  (string) $this->request->resourceObject->uri;
        $this->etags->addTag($this->request->resourceObject);
        $this->donutStorage->setView($uri, $view);

        return sprintf(ResourceDonut::FOMRAT, $uri);
    }
}
