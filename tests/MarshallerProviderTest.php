<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Cache\Marshaller\DefaultMarshaller;
use Symfony\Component\Cache\Marshaller\DeflateMarshaller;
use Symfony\Component\Cache\Marshaller\SodiumMarshaller;

use function base64_encode;
use function extension_loaded;
use function random_bytes;
use function sodium_crypto_box_keypair;

final class MarshallerProviderTest extends TestCase
{
    public function testGetMarshallerWithDisabledOption(): void
    {
        $options = ['enabled' => false];
        $provider = new MarshallerProvider($options);
        $marshaller = $provider->get();

        $this->assertNull($marshaller);
    }

    public function testGetMarshallerWithDefaultType(): void
    {
        $options = [
            'enabled' => true,
            'type' => 'default',
            'use_igbinary' => false,
        ];
        $provider = new MarshallerProvider($options);
        $marshaller = $provider->get();

        $this->assertInstanceOf(DefaultMarshaller::class, $marshaller);
    }

    public function testGetMarshallerWithDefaultTypeAndIgbinary(): void
    {
        if (! extension_loaded('igbinary')) {
            $this->markTestSkipped('igbinary extension is not available');
        }

        $options = [
            'enabled' => true,
            'type' => 'default',
            'use_igbinary' => true,
        ];
        $provider = new MarshallerProvider($options);
        $marshaller = $provider->get();

        $this->assertInstanceOf(DefaultMarshaller::class, $marshaller);
    }

    public function testGetMarshallerWithDeflateType(): void
    {
        if (! extension_loaded('zlib')) {
            $this->markTestSkipped('zlib extension is not available');
        }

        $options = [
            'enabled' => true,
            'type' => 'deflate',
            'use_igbinary' => false,
        ];
        $provider = new MarshallerProvider($options);
        $marshaller = $provider->get();

        $this->assertInstanceOf(DeflateMarshaller::class, $marshaller);
    }

    public function testGetMarshallerWithSodiumType(): void
    {
        if (! extension_loaded('sodium')) {
            $this->markTestSkipped('sodium extension is not available');
        }

        $options = [
            'enabled' => true,
            'type' => 'sodium',
            'keys' => [base64_encode(random_bytes(32))],
            'use_igbinary' => false,
        ];
        $provider = new MarshallerProvider($options);
        $marshaller = $provider->get();

        $this->assertInstanceOf(SodiumMarshaller::class, $marshaller);
    }

    public function testGetMarshallerWithSodiumTypeAndKeypair(): void
    {
        if (! extension_loaded('sodium')) {
            $this->markTestSkipped('sodium extension is not available');
        }

        $keypair = sodium_crypto_box_keypair();
        $options = [
            'enabled' => true,
            'type' => 'sodium',
            'keys' => [base64_encode($keypair)],
            'use_igbinary' => false,
        ];
        $provider = new MarshallerProvider($options);
        $marshaller = $provider->get();

        $this->assertInstanceOf(SodiumMarshaller::class, $marshaller);
    }

    public function testGetMarshallerWithSodiumTypeAndBinaryKey(): void
    {
        if (! extension_loaded('sodium')) {
            $this->markTestSkipped('sodium extension is not available');
        }

        $binaryKey = random_bytes(32);
        $options = [
            'enabled' => true,
            'type' => 'sodium',
            'keys' => [$binaryKey],
            'use_igbinary' => false,
        ];
        $provider = new MarshallerProvider($options);
        $marshaller = $provider->get();

        $this->assertInstanceOf(SodiumMarshaller::class, $marshaller);
    }

    public function testGetMarshallerWithSodiumTypeAndInvalidKeyFormat(): void
    {
        if (! extension_loaded('sodium')) {
            $this->markTestSkipped('sodium extension is not available');
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid sodium key format or length');

        $options = [
            'enabled' => true,
            'type' => 'sodium',
            'keys' => ['invalid_key_format'],
            'use_igbinary' => false,
        ];
        $provider = new MarshallerProvider($options);
        $provider->get();
    }

    public function testGetMarshallerWithSodiumTypeAndInvalidKeyLength(): void
    {
        if (! extension_loaded('sodium')) {
            $this->markTestSkipped('sodium extension is not available');
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid sodium key format or length');

        $options = [
            'enabled' => true,
            'type' => 'sodium',
            'keys' => [base64_encode(random_bytes(16))], // Wrong length
            'use_igbinary' => false,
        ];
        $provider = new MarshallerProvider($options);
        $provider->get();
    }

    public function testGetMarshallerWithSodiumTypeAndNonStringKey(): void
    {
        if (! extension_loaded('sodium')) {
            $this->markTestSkipped('sodium extension is not available');
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('All keys must be strings');

        $options = [
            'enabled' => true,
            'type' => 'sodium',
            'keys' => [123], // Non-string key
            'use_igbinary' => false,
        ];
        $provider = new MarshallerProvider($options);
        $provider->get();
    }

    public function testGetMarshallerWithInvalidType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid marshaller type: invalid');

        $options = [
            'enabled' => true,
            'type' => 'invalid',
        ];
        $provider = new MarshallerProvider($options);
        $provider->get();
    }

    public function testGetMarshallerWithSodiumTypeButMissingKeys(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Keys are required for sodium marshaller');

        $options = [
            'enabled' => true,
            'type' => 'sodium',
            'use_igbinary' => false,
        ];
        $provider = new MarshallerProvider($options);
        $provider->get();
    }

    public function testGetMarshallerWithEmptyOptions(): void
    {
        $provider = new MarshallerProvider([]);
        $marshaller = $provider->get();

        $this->assertNull($marshaller);
    }

    public function testGetMarshallerWithZlibNotAvailableForDeflate(): void
    {
        if (extension_loaded('zlib')) {
            $this->markTestSkipped('zlib extension is available');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('zlib extension is required for deflate marshaller');

        $options = [
            'enabled' => true,
            'type' => 'deflate',
        ];
        $provider = new MarshallerProvider($options);
        $provider->get();
    }

    public function testGetMarshallerWithSodiumNotAvailableForSodium(): void
    {
        if (extension_loaded('sodium')) {
            $this->markTestSkipped('sodium extension is available');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sodium extension is required for sodium marshaller');

        $options = [
            'enabled' => true,
            'type' => 'sodium',
            'keys' => ['dummy'],
        ];
        $provider = new MarshallerProvider($options);
        $provider->get();
    }
}
