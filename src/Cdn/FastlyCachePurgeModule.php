<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Cdn;

use BEAR\QueryRepository\PurgerInterface;
use Fastly\Api\PurgeApi;
use Fastly\Configuration;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Ray\Di\AbstractModule;

final class FastlyCachePurgeModule extends AbstractModule
{
    private string $fastlyApiKey;
    private string $fastlyServiceId;
    private bool $enableSoftPurge;

    /**
     * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
     */
    public function __construct(
        string $fastlyApiKey,
        string $fastlyServiceId,
        bool $enableSoftPurge = true,
        ?AbstractModule $module = null
    ) {
        $this->fastlyApiKey = $fastlyApiKey;
        $this->fastlyServiceId = $fastlyServiceId;
        $this->enableSoftPurge = $enableSoftPurge;

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
        ]);
        $this->bind()->annotatedWith('FASTLY_SERVICE_ID')->toInstance($this->fastlyServiceId);
        $this->bind()->annotatedWith('FASTLY_ENABLE_SOFT_PURGE')->toInstance($this->enableSoftPurge);
        $this->bind(ClientInterface::class)->annotatedWith('fastly')
            ->toConstructor(Client::class, ['config' => 'fastly_http_client_options']);
        $this->bind(PurgerInterface::class)->to(FastlyCachePurger::class);
    }
}
