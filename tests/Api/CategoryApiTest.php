<?php

declare(strict_types=1);

namespace Tests\Api;

use App\Database\ConnectionFactory;
use App\Http\ApiKernel;
use App\Http\Request;
use App\Http\Response;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\ArraySession;
use Tests\Support\TestApplication;

final class CategoryApiTest extends TestCase
{
    private PDO $pdo;
    private TestApplication $application;

    protected function setUp(): void
    {
        $this->pdo = ConnectionFactory::createMemory();
        $this->application = new TestApplication($this->pdo, dirname(__DIR__, 2) . '/migrations');
        $this->application->userService->create('alice', 'correct horse battery staple');
        $this->application->userService->create('bob', 'another correct horse battery');
    }

    public function testAuthenticationIsRequiredForEveryCategoryRoute(): void
    {
        $session = new ArraySession();
        $kernel = $this->application->kernel($session);
        $csrf = $this->decode($kernel->handle(new Request('GET', '/api/auth/csrf')))['data']['csrf_token'];
        self::assertIsString($csrf);

        self::assertSame(401, $kernel->handle(new Request('GET', '/api/categories'))->status);
        self::assertSame(401, $kernel->handle(new Request(
            'POST',
            '/api/categories',
            ['content-type' => 'application/json', 'x-csrf-token' => $csrf],
            '{"name":"Privée"}',
        ))->status);
        self::assertSame(401, $kernel->handle(new Request(
            'PATCH',
            '/api/categories/1',
            ['content-type' => 'application/json', 'x-csrf-token' => $csrf],
            '{"name":"Privée"}',
        ))->status);
        self::assertSame(401, $kernel->handle(new Request(
            'DELETE',
            '/api/categories/1',
            ['x-csrf-token' => $csrf],
        ))->status);
    }

    public function testUserCanCreateListRenameAndDeleteCategory(): void
    {
        [$kernel, $csrf, $userId] = $this->authenticatedKernel('alice', 'correct horse battery staple');
        $second = $this->createCategory($kernel, $csrf, '  Zeste  ');
        self::assertSame(201, $second->status);
        $secondData = $this->decode($second)['data'];
        self::assertSame('Zeste', $secondData['name']);
        self::assertSame('2026-09-24T12:00:00Z', $secondData['created_at']);
        self::assertArrayNotHasKey('user_id', $secondData);
        $secondId = $secondData['id'];
        self::assertIsInt($secondId);

        $first = $this->decode($this->createCategory($kernel, $csrf, 'Actualités'))['data'];
        $listed = $this->decode($kernel->handle(new Request('GET', '/api/categories')))['data'];
        self::assertSame([$first['id'], $secondId], array_column($listed, 'id'));

        $updated = $kernel->handle(new Request(
            'PATCH',
            '/api/categories/' . $secondId,
            ['content-type' => 'application/json', 'x-csrf-token' => $csrf],
            '{"name":"Développement"}',
        ));
        self::assertSame(200, $updated->status);
        self::assertSame('Développement', $this->decode($updated)['data']['name']);

        $this->insertFeed($userId, $secondId);
        self::assertSame(204, $kernel->handle(new Request(
            'DELETE',
            '/api/categories/' . $secondId,
            ['x-csrf-token' => $csrf],
        ))->status);
        self::assertSame(1, $this->integerQuery('SELECT COUNT(*) FROM feeds'));
        $feedCategory = $this->pdo->query('SELECT category_id FROM feeds');
        self::assertNotFalse($feedCategory);
        $row = $feedCategory->fetch();
        self::assertIsArray($row);
        self::assertNull($row['category_id']);
    }

    public function testCategoryOwnershipIsEnforcedForListUpdateAndDelete(): void
    {
        [$aliceKernel, $aliceCsrf] = $this->authenticatedKernel('alice', 'correct horse battery staple');
        [$bobKernel, $bobCsrf] = $this->authenticatedKernel('bob', 'another correct horse battery');
        $aliceCategory = $this->decode($this->createCategory($aliceKernel, $aliceCsrf, 'Privée'))['data'];
        $categoryId = $aliceCategory['id'];
        self::assertIsInt($categoryId);

        self::assertSame([], $this->decode(
            $bobKernel->handle(new Request('GET', '/api/categories'))
        )['data']);
        self::assertSame(404, $bobKernel->handle(new Request(
            'PATCH',
            '/api/categories/' . $categoryId,
            ['content-type' => 'application/json', 'x-csrf-token' => $bobCsrf],
            '{"name":"Volée"}',
        ))->status);
        self::assertSame(404, $bobKernel->handle(new Request(
            'DELETE',
            '/api/categories/' . $categoryId,
            ['x-csrf-token' => $bobCsrf],
        ))->status);

        $aliceList = $this->decode($aliceKernel->handle(new Request('GET', '/api/categories')))['data'];
        self::assertSame([$categoryId], array_column($aliceList, 'id'));
    }

    public function testDuplicateNamesAreRejectedPerUserButAllowedForAnotherUser(): void
    {
        [$aliceKernel, $aliceCsrf] = $this->authenticatedKernel('alice', 'correct horse battery staple');
        [$bobKernel, $bobCsrf] = $this->authenticatedKernel('bob', 'another correct horse battery');
        $first = $this->decode($this->createCategory($aliceKernel, $aliceCsrf, 'Technique'))['data'];
        $second = $this->decode($this->createCategory($aliceKernel, $aliceCsrf, 'Actualités'))['data'];

        $duplicate = $this->createCategory($aliceKernel, $aliceCsrf, 'technique');
        self::assertSame(409, $duplicate->status);
        self::assertSame('CATEGORY_ALREADY_EXISTS', $this->decode($duplicate)['error']['code']);
        $renameDuplicate = $aliceKernel->handle(new Request(
            'PATCH',
            '/api/categories/' . $second['id'],
            ['content-type' => 'application/json', 'x-csrf-token' => $aliceCsrf],
            json_encode(['name' => $first['name']], JSON_THROW_ON_ERROR),
        ));
        self::assertSame(409, $renameDuplicate->status);
        self::assertSame('CATEGORY_ALREADY_EXISTS', $this->decode($renameDuplicate)['error']['code']);
        self::assertSame(201, $this->createCategory($bobKernel, $bobCsrf, 'TECHNIQUE')->status);
    }

    public function testWritesRequireCsrfAndValidateTheirExactContract(): void
    {
        [$kernel, $csrf] = $this->authenticatedKernel('alice', 'correct horse battery staple');

        self::assertSame(403, $kernel->handle(new Request(
            'POST',
            '/api/categories',
            ['content-type' => 'application/json'],
            '{"name":"Sans jeton"}',
        ))->status);
        self::assertSame(422, $this->createCategory($kernel, $csrf, '   ')->status);
        self::assertSame(422, $this->createCategory($kernel, $csrf, str_repeat('a', 201))->status);
        self::assertSame(422, $kernel->handle(new Request(
            'POST',
            '/api/categories',
            ['content-type' => 'application/json', 'x-csrf-token' => $csrf],
            '{"name":"Valide","extra":true}',
        ))->status);
        self::assertSame(422, $kernel->handle(new Request(
            'POST',
            '/api/categories',
            ['content-type' => 'application/json', 'x-csrf-token' => $csrf],
            '{"name":42}',
        ))->status);

        $category = $this->decode($this->createCategory($kernel, $csrf, 'Valide'))['data'];
        self::assertSame(422, $kernel->handle(new Request(
            'PATCH',
            '/api/categories/' . $category['id'],
            ['content-type' => 'application/json', 'x-csrf-token' => $csrf],
            '{}',
        ))->status);
    }

    /** @return array{ApiKernel, string, int} */
    private function authenticatedKernel(string $username, string $password): array
    {
        $session = new ArraySession();
        $kernel = $this->application->kernel($session);
        $csrf = $this->decode($kernel->handle(new Request('GET', '/api/auth/csrf')))['data']['csrf_token'];
        self::assertIsString($csrf);
        $login = $kernel->handle(new Request(
            'POST',
            '/api/auth/login',
            ['content-type' => 'application/json', 'x-csrf-token' => $csrf],
            json_encode(['username' => $username, 'password' => $password], JSON_THROW_ON_ERROR),
            '192.0.2.10',
        ));
        self::assertSame(200, $login->status);
        $data = $this->decode($login)['data'];
        self::assertIsString($data['csrf_token']);
        self::assertIsInt($data['user']['id']);

        return [$kernel, $data['csrf_token'], $data['user']['id']];
    }

    private function createCategory(ApiKernel $kernel, string $csrf, string $name): Response
    {
        return $kernel->handle(new Request(
            'POST',
            '/api/categories',
            ['content-type' => 'application/json', 'x-csrf-token' => $csrf],
            json_encode(['name' => $name], JSON_THROW_ON_ERROR),
        ));
    }

    private function insertFeed(int $userId, int $categoryId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO feeds '
            . '(user_id, category_id, name, feed_url, is_active, last_fetch_status, created_at, updated_at) '
            . 'VALUES (:user_id, :category_id, :name, :feed_url, 1, :status, :created_at, :updated_at)'
        );
        $statement->execute([
            'user_id' => $userId,
            'category_id' => $categoryId,
            'name' => 'Flux classé',
            'feed_url' => 'https://example.org/category.xml',
            'status' => 'never',
            'created_at' => '2026-09-24T12:00:00Z',
            'updated_at' => '2026-09-24T12:00:00Z',
        ]);
    }

    private function integerQuery(string $sql): int
    {
        $statement = $this->pdo->query($sql);
        self::assertNotFalse($statement);

        return (int) $statement->fetchColumn();
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        $decoded = json_decode($response->body, true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
