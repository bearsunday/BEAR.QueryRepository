<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use InvalidArgumentException;
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
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid marshaller type: invalid');

        $options = [
            'enabled' => true,
            'type' => 'invalid',
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

    public function testGetMarshallerWithNonStringType(): void
    {
        $options = [
            'enabled' => true,
            'type' => 123, // Non-string type should default to 'default'
            'use_igbinary' => false,
        ];
        $provider = new MarshallerProvider($options);
        $marshaller = $provider->get();

        $this->assertInstanceOf(DefaultMarshaller::class, $marshaller);
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
