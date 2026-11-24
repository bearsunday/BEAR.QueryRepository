<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\MarshallerOptions;
use Override;
use Ray\Di\ProviderInterface;
use Symfony\Component\Cache\Marshaller\DefaultMarshaller;
use Symfony\Component\Cache\Marshaller\DeflateMarshaller;
use Symfony\Component\Cache\Marshaller\MarshallerInterface;

/**
 * @implements ProviderInterface<MarshallerInterface|null>
 * @internal
 */
final readonly class MarshallerProvider implements ProviderInterface
{
    /** Without options, values are compressed with deflate; `['enabled' => false]` stores them uncompressed */
    private const DEFAULT_OPTIONS = ['enabled' => true, 'type' => 'deflate'];

    /** @param array{enabled?: bool, type?: string, use_igbinary?: bool} $options Marshalling options */
    public function __construct(
        #[MarshallerOptions]
        private array $options = [],
    ) {
    }

    #[Override]
    public function get(): MarshallerInterface|null
    {
        return $this->createMarshaller($this->options === [] ? self::DEFAULT_OPTIONS : $this->options);
    }

    /** @param array{enabled?: bool, type?: string, use_igbinary?: bool} $options */
    private function createMarshaller(array $options): MarshallerInterface|null
    {
        if (($options['enabled'] ?? false) !== true) {
            return null;
        }

        $typeString = $options['type'] ?? 'default';
        $type = MarshallerType::tryFrom($typeString) ?? MarshallerType::DEFAULT;
        $useIgbinary = $options['use_igbinary'] ?? false;

        return match ($type) {
            MarshallerType::DEFAULT => $this->createDefaultMarshaller($useIgbinary),
            MarshallerType::DEFLATE => $this->createDeflateMarshaller($useIgbinary),
        };
    }

    private function createDefaultMarshaller(bool $useIgbinary): DefaultMarshaller
    {
        return new DefaultMarshaller($useIgbinary);
    }

    private function createDeflateMarshaller(bool $useIgbinary): DeflateMarshaller
    {
        $defaultMarshaller = $this->createDefaultMarshaller($useIgbinary);

        return new DeflateMarshaller($defaultMarshaller);
    }
}
