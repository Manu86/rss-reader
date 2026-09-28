<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\Exception\ValidationException;
use App\Repository\UserRepository;
use App\Security\PasswordPolicy;
use App\Service\UserService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tests\Support\FrozenClock;

final class UserServiceTest extends TestCase
{
    private UserRepository $repository;
    private UserService $service;

    protected function setUp(): void
    {
        $pdo = ConnectionFactory::createMemory();
        (new Migrator($pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->repository = new UserRepository($pdo);
        $this->service = new UserService(
            $this->repository,
            new PasswordPolicy(),
            new FrozenClock(new DateTimeImmutable('2026-09-24T12:00:00Z')),
        );
    }

    public function testCreateUserAlsoCreatesSettingsAndDoesNotExposeHashInListing(): void
    {
        $created = $this->service->create('alice', 'correct horse battery staple');
        $listed = $this->repository->listAll();

        self::assertSame('alice', $created->username);
        self::assertTrue(password_verify('correct horse battery staple', $created->passwordHash));
        self::assertCount(1, $listed);
        self::assertSame('alice', $listed[0]['username']);
        self::assertArrayNotHasKey('password_hash', $listed[0]);
    }

    public function testUsernamesAreUniqueWithoutCaseSensitivity(): void
    {
        $this->service->create('Alice', 'correct horse battery staple');

        $this->expectException(ValidationException::class);
        $this->service->create('alice', 'another correct horse battery');
    }

    public function testPasswordMustHaveAtLeastTwelveCharacters(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->create('alice', 'too-short');
    }

    public function testPasswordLongerThanBcryptLimitIsRejectedInsteadOfTruncated(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->create('alice', str_repeat('a', 73));
    }

    public function testUserCanBeDisabledAndReenabled(): void
    {
        $user = $this->service->create('alice', 'correct horse battery staple');
        self::assertTrue($this->service->setActive('alice', false));
        self::assertNull($this->repository->findActiveById($user->id));
        self::assertTrue($this->service->setActive('alice', true));
        self::assertNotNull($this->repository->findActiveById($user->id));
    }

    public function testUserCanUpdateOptionalEmail(): void
    {
        $user = $this->service->create('alice', 'correct horse battery staple');

        $updated = $this->service->updateOwnProfile($user, ' alice@example.org ', 'daily');
        self::assertSame('alice@example.org', $updated->email);
        self::assertNull($this->service->updateOwnProfile($updated, '', 'never')->email);
    }

    public function testInvalidEmailIsRejected(): void
    {
        $user = $this->service->create('alice', 'correct horse battery staple');

        $this->expectException(ValidationException::class);
        $this->service->updateOwnProfile($user, 'adresse-invalide', 'daily');
    }

    public function testEmailFrequencyRequiresAnEmail(): void
    {
        $user = $this->service->create('alice', 'correct horse battery staple');

        $this->expectException(ValidationException::class);
        $this->service->updateOwnProfile($user, '', 'weekly');
    }
}
