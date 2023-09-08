<?php

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Cdn\FastlyCachePurger;
use Fastly\Api\PurgeApi;
use Fastly\Configuration;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Ray\Di\AbstractModule;
use Ray\Di\Scope;

final class FakeFastlyPurgeModule extends AbstractModule
{
    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this->bind(Configuration::class)->annotatedWith(Configuration::class)->toInstance(
            Configuration::getDefaultConfiguration()->setApiToken('fakeKey')
        );
        $this->bind(PurgeApi::class)->toConstructor(FakeFastlyPurgeApi::class, [
            'config' => Configuration::class,
        ])->in(Scope::SINGLETON);
        $this->bind()->annotatedWith('fastlyServiceId')->toInstance('fakeServiceId');
        $this->bind()->annotatedWith('fastlyEnableSoftPurge')->toInstance(true);
        $this->bind(ClientInterface::class)->annotatedWith('fastlyApi')->to(Client::class);
        $this->bind(PurgerInterface::class)->to(FastlyCachePurger::class);
    }
}
