<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Exception\RemoteHttpException;
use App\Security\IpAddressValidator;
use App\Security\RemoteUrlGuard;
use App\Validation\UrlNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeDnsResolver;

final class RemoteUrlGuardTest extends TestCase
{
    public function testItResolvesAndAcceptsOnlyPublicAnswers(): void
    {
        $guard = $this->guard(['example.org' => ['93.184.216.34', '2606:4700:4700::1111']]);
        $resolved = $guard->resolve('HTTPS://Example.Org:443/feed.xml#fragment');

        self::assertSame('https://example.org/feed.xml', $resolved->url);
        self::assertSame('example.org', $resolved->host);
        self::assertSame(443, $resolved->port);
        self::assertSame(['93.184.216.34', '2606:4700:4700::1111'], $resolved->addresses);
    }

    public function testItRejectsMixedPublicAndPrivateDnsAnswers(): void
    {
        $guard = $this->guard(['example.org' => ['93.184.216.34', '127.0.0.1']]);

        $exception = $this->captureException(
            static fn() => $guard->resolve('https://example.org/feed'),
        );
        self::assertSame('UNSAFE_ADDRESS', $exception->reason);
    }

    public function testItRejectsMissingDnsAnswers(): void
    {
        $guard = $this->guard([]);
        $exception = $this->captureException(
            static fn() => $guard->resolve('https://missing.example/feed'),
        );
        self::assertSame('DNS_FAILED', $exception->reason);
    }

    #[DataProvider('unsafeUrls')]
    public function testItRejectsUnsafeUrlSyntax(string $url): void
    {
        $guard = $this->guard([]);
        $exception = $this->captureException(
            static fn() => $guard->resolve($url),
        );
        self::assertSame('UNSAFE_URL', $exception->reason);
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeUrls(): iterable
    {
        yield 'file scheme' => ['file:///etc/passwd'];
        yield 'ftp scheme' => ['ftp://example.org/feed'];
        yield 'credentials' => ['https://user:secret@example.org/feed'];
        yield 'relative' => ['/feed'];
    }

    /** @param array<string, list<string>> $answers */
    private function guard(array $answers): RemoteUrlGuard
    {
        return new RemoteUrlGuard(
            new UrlNormalizer(),
            new FakeDnsResolver($answers),
            new IpAddressValidator(),
        );
    }

    /** @param callable(): mixed $operation */
    private function captureException(callable $operation): RemoteHttpException
    {
        try {
            $operation();
            self::fail('Une exception RemoteHttpException était attendue.');
        } catch (RemoteHttpException $exception) {
            return $exception;
        }
    }
}
