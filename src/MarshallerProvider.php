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
use Symfony\Component\Cache\Marshaller\SodiumMarshaller;

use function base64_decode;
use function extension_loaded;
use function is_array;
use function is_string;
use function sprintf;
use function strlen;

use const SODIUM_CRYPTO_BOX_KEYPAIRBYTES;

/**
 * Provider for creating marshaller instances based on configuration options
 *
 * Supports the following marshaller types:
 * - 'default': Basic marshaller with optional igbinary support
 * - 'deflate': Compression-enabled marshaller (requires zlib extension)
 * - 'sodium': Encryption-enabled marshaller (requires sodium extension)
 *
 * For sodium marshallers, keys can be provided as:
 * - Base64-encoded strings (recommended for configuration files)
 * - Binary strings (32 bytes or SODIUM_CRYPTO_BOX_KEYPAIRBYTES length)
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
            'sodium' => $this->createSodiumMarshaller($options, $useIgbinary),
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

    /** @param array<string, mixed> $options */
    private function createSodiumMarshaller(array $options, bool $useIgbinary): SodiumMarshaller
    {
        if (! extension_loaded('sodium')) {
            throw new RuntimeException('sodium extension is required for sodium marshaller');
        }

        if (! isset($options['keys']) || ! is_array($options['keys']) || empty($options['keys'])) {
            throw new InvalidArgumentException('Keys are required for sodium marshaller');
        }

        /** @var array<mixed> $rawKeys */
        $rawKeys = $options['keys'];
        $processedKeys = $this->processSodiumKeys($rawKeys);
        $defaultMarshaller = $this->createDefaultMarshaller($useIgbinary);

        return new SodiumMarshaller($processedKeys, $defaultMarshaller);
    }

    /**
     * Process and validate sodium encryption keys
     *
     * @param array<mixed> $keys Raw encryption keys (base64 encoded or binary)
     *
     * @return array<string> Processed binary encryption keys
     */
    private function processSodiumKeys(array $keys): array
    {
        $processedKeys = [];

        foreach ($keys as $key) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('All keys must be strings');
            }

            // Try to decode as base64 first (common format in Symfony configs)
            $decodedKey = base64_decode($key, true);
            if ($decodedKey !== false) {
                // Validate key length for sodium (should be 32 bytes for crypto_box)
                if (strlen($decodedKey) === SODIUM_CRYPTO_BOX_KEYPAIRBYTES || strlen($decodedKey) === 32) {
                    $processedKeys[] = $decodedKey;
                    continue;
                }
            }

            // If not valid base64, assume it's already binary and validate length
            if (strlen($key) === SODIUM_CRYPTO_BOX_KEYPAIRBYTES || strlen($key) === 32) {
                $processedKeys[] = $key;
                continue;
            }

            throw new InvalidArgumentException(sprintf(
                'Invalid sodium key format or length. Expected base64-encoded key or binary key of length %d or %d bytes',
                SODIUM_CRYPTO_BOX_KEYPAIRBYTES,
                32,
            ));
        }

        return $processedKeys;
    }
}
