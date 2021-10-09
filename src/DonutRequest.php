<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractRequest;

use function sprintf;

final class DonutRequest
{
    /** @var AbstractRequest */
    private $request;

    /** @var DonutRenderer */
    private $donutStorage;

    /** @var SurrogateKeys */
    private $etags;

    public function __construct(AbstractRequest $request, DonutRenderer $donutStorage, SurrogateKeys $etags)
    {
        $this->request = $request;
        $this->donutStorage = $donutStorage;
        $this->etags = $etags;
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