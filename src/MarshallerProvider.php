<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\MarshallerOptions;
use InvalidArgumentException;
use Override;
use Ray\Di\ProviderInterface;
use RuntimeException;
use Symfony\Component\Cache\Marshaller\DefaultMarshaller;
use Symfony\Component\Cache\Marshaller\DeflateMarshaller;
use Symfony\Component\Cache\Marshaller\MarshallerInterface;

use function extension_loaded;
use function is_string;
use function sprintf;

/**
 * Provider for creating marshaller instances based on configuration options
 *
 * Supports the following marshaller types:
 * - 'default': Basic marshaller with optional igbinary support
 * - 'deflate': Compression-enabled marshaller (requires zlib extension)
 *
 * @implements ProviderInterface<MarshallerInterface|null>
 */
final class MarshallerProvider implements ProviderInterface
{
    /** @param array<string, mixed> $options Marshalling options */
    public function __construct(
        #[MarshallerOptions]
        private readonly array $options = [],
    ) {
    }

    #[Override]
    public function get(): MarshallerInterface|null
    {
        return $this->createMarshaller($this->options);
    }

    /**
     * Create marshaller instance based on options
     *
     * @param array<string, mixed> $options
     */
    private function createMarshaller(array $options): MarshallerInterface|null
    {
        if (empty($options) || ($options['enabled'] ?? false) !== true) {
            return null;
        }

        /** @var string $type */
        $type = is_string($options['type'] ?? null) ? $options['type'] : 'default';
        $useIgbinary = (bool) ($options['use_igbinary'] ?? false);

        return match ($type) {
            'default' => $this->createDefaultMarshaller($useIgbinary),
            'deflate' => $this->createDeflateMarshaller($useIgbinary),
            default => throw new InvalidArgumentException(sprintf('Invalid marshaller type: %s', $type)),
        };
    }

    private function createDefaultMarshaller(bool $useIgbinary): DefaultMarshaller
    {
        if ($useIgbinary && ! extension_loaded('igbinary')) {
            throw new RuntimeException('igbinary extension is required for igbinary marshaller');
        }

        return new DefaultMarshaller($useIgbinary);
    }

    private function createDeflateMarshaller(bool $useIgbinary): DeflateMarshaller
    {
        if (! extension_loaded('zlib')) {
            throw new RuntimeException('zlib extension is required for deflate marshaller');
        }

        $defaultMarshaller = $this->createDefaultMarshaller($useIgbinary);

        return new DeflateMarshaller($defaultMarshaller);
    }
}
