<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Cdn;

use BEAR\QueryRepository\PurgerInterface;
use Fastly\Api\PurgeApi;
use Fastly\Configuration;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Ray\Di\AbstractModule;
use Ray\Di\Scope;

final class FastlyPurgeModule extends AbstractModule
{
    /**
     * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
     */
    public function __construct(
        private string $fastlyApiKey,
        private string $fastlyServiceId,
        private bool $enableSoftPurge = true,
        ?AbstractModule $module = null
    ) {
        parent::__construct($module);
    }

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this->bind(Configuration::class)->annotatedWith(Configuration::class)->toInstance(
            Configuration::getDefaultConfiguration()->setApiToken($this->fastlyApiKey)
        );
        $this->bind(PurgeApi::class)->toConstructor(PurgeApi::class, [
            'config' => Configuration::class,
        ])->in(Scope::SINGLETON);
        $this->bind()->annotatedWith('fastlyServiceId')->toInstance($this->fastlyServiceId);
        $this->bind()->annotatedWith('fastlyEnableSoftPurge')->toInstance($this->enableSoftPurge);
        $this->bind(ClientInterface::class)->annotatedWith('fastlyApi')
            ->toConstructor(Client::class, ['config' => 'fastly_http_client_options']);
        $this->bind(PurgerInterface::class)->to(FastlyCachePurger::class);
    }
}
