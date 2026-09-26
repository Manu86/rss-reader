<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Security\IpAddressValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IpAddressValidatorTest extends TestCase
{
    #[DataProvider('blockedAddresses')]
    public function testItBlocksNonPublicAddresses(string $address): void
    {
        self::assertFalse((new IpAddressValidator())->isPublic($address));
    }

    #[DataProvider('publicAddresses')]
    public function testItAllowsPublicAddresses(string $address): void
    {
        self::assertTrue((new IpAddressValidator())->isPublic($address));
    }

    /** @return iterable<string, array{string}> */
    public static function blockedAddresses(): iterable
    {
        yield 'invalid' => ['not-an-address'];
        yield 'unspecified IPv4' => ['0.0.0.1'];
        yield 'private 10' => ['10.0.0.1'];
        yield 'shared carrier' => ['100.64.0.1'];
        yield 'loopback' => ['127.0.0.1'];
        yield 'link local IPv4' => ['169.254.1.1'];
        yield 'private 172' => ['172.31.255.255'];
        yield 'private 192' => ['192.168.1.1'];
        yield 'benchmark' => ['198.19.0.1'];
        yield 'documentation' => ['203.0.113.1'];
        yield 'multicast' => ['224.0.0.1'];
        yield 'reserved' => ['255.255.255.255'];
        yield 'unspecified IPv6' => ['::'];
        yield 'loopback IPv6' => ['::1'];
        yield 'IPv4 mapped IPv6' => ['::ffff:127.0.0.1'];
        yield 'unique local IPv6' => ['fd00::1'];
        yield 'link local IPv6' => ['fe80::1'];
        yield 'site local IPv6' => ['fec0::1'];
        yield 'multicast IPv6' => ['ff02::1'];
        yield 'documentation IPv6' => ['2001:db8::1'];
    }

    /** @return iterable<string, array{string}> */
    public static function publicAddresses(): iterable
    {
        yield 'public IPv4' => ['93.184.216.34'];
        yield 'public DNS IPv4' => ['8.8.8.8'];
        yield 'public IPv6' => ['2606:4700:4700::1111'];
        yield 'public DNS IPv6' => ['2001:4860:4860::8888'];
    }
}
