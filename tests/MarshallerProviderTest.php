<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Marshaller\DefaultMarshaller;
use Symfony\Component\Cache\Marshaller\DeflateMarshaller;

use function extension_loaded;

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

    public function testGetMarshallerWithInvalidType(): void
    {
        $options = [
            'enabled' => true,
            'type' => 'invalid',
        ];
        $provider = new MarshallerProvider($options);
        $marshaller = $provider->get();

        // Invalid type should fallback to default
        $this->assertInstanceOf(DefaultMarshaller::class, $marshaller);
    }

    public function testGetMarshallerWithEmptyOptions(): void
    {
        $provider = new MarshallerProvider([]);
        $marshaller = $provider->get();

        $this->assertNull($marshaller);
    }

    public function testGetMarshallerWithDeflateTypeAndIgbinary(): void
    {
        if (! extension_loaded('zlib')) {
            $this->markTestSkipped('zlib extension is not available');
        }

        if (! extension_loaded('igbinary')) {
            $this->markTestSkipped('igbinary extension is not available');
        }

        $options = [
            'enabled' => true,
            'type' => 'deflate',
            'use_igbinary' => true,
        ];
        $provider = new MarshallerProvider($options);
        $marshaller = $provider->get();

        $this->assertInstanceOf(DeflateMarshaller::class, $marshaller);
    }

    public function testGetMarshallerWithTypeNotSpecified(): void
    {
        $options = [
            'enabled' => true,
            // type not specified should default to 'default'
            'use_igbinary' => false,
        ];
        $provider = new MarshallerProvider($options);
        $marshaller = $provider->get();

        $this->assertInstanceOf(DefaultMarshaller::class, $marshaller);
    }
}
