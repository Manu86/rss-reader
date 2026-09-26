<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\SystemDnsResolver;
use PHPUnit\Framework\TestCase;

final class SystemDnsResolverTest extends TestCase
{
    public function testIpLiteralsDoNotRequireDnsLookup(): void
    {
        $resolver = new SystemDnsResolver();

        self::assertSame(['93.184.216.34'], $resolver->resolve('93.184.216.34'));
        self::assertSame(['2606:4700:4700::1111'], $resolver->resolve('[2606:4700:4700::1111]'));
    }
}
