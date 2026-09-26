<?php

declare(strict_types=1);

namespace Tests\Api;

use App\Database\ConnectionFactory;
use App\Http\ApiKernel;
use App\Http\Request;
use App\Http\Response;
use App\Http\UploadedFile;
use DOMDocument;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\ArraySession;
use Tests\Support\TestApplication;

final class OpmlApiTest extends TestCase
{
    private PDO $pdo;
    private TestApplication $application;
    private string $fixture;

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        $this->pdo = ConnectionFactory::createMemory();
        $this->application = new TestApplication($this->pdo, dirname(__DIR__, 2) . '/migrations');
        $this->application->userService->create('alice', 'correct horse battery staple');
        $this->application->userService->create('bob', 'another correct horse battery');
        $this->fixture = dirname(__DIR__) . '/Fixtures/Opml/valid.opml';
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testAuthenticationIsRequiredForImportAndExport(): void
    {
        $session = new ArraySession();
        $kernel = $this->application->kernel($session);
        $csrf = $this->decode($kernel->handle(new Request('GET', '/api/auth/csrf')))['data']['csrf_token'];
        self::assertIsString($csrf);

        self::assertSame(401, $kernel->handle(new Request('GET', '/api/opml/export'))->status);
        self::assertSame(401, $kernel->handle($this->importRequest(
            $csrf,
            $this->uploadedFixture(),
        ))->status);
    }

    public function testImportCreatesCategoriesImportsFeedsAndReportsDuplicates(): void
    {
        [$kernel, $csrf, $userId] = $this->authenticatedKernel('alice', 'correct horse battery staple');

        $response = $kernel->handle($this->importRequest($csrf, $this->uploadedFixture()));
        self::assertSame(200, $response->status);
        self::assertSame([
            'imported' => 4,
            'duplicates' => 0,
            'failed' => 0,
            'categories_created' => 3,
        ], $this->decode($response)['data']);
        self::assertSame(['Actualités', 'Technique', 'Vide'], $this->categoryNames($userId));
        self::assertSame([
            ['feed_url' => 'https://example.net/direct.xml', 'category_name' => null],
            ['feed_url' => 'https://example.net/sqlite.xml', 'category_name' => 'Technique'],
            ['feed_url' => 'https://example.org/news.xml', 'category_name' => 'Actualités'],
            ['feed_url' => 'https://example.org/php.xml', 'category_name' => 'Technique'],
        ], $this->feedStructure($userId));

        $duplicate = $kernel->handle($this->importRequest($csrf, $this->uploadedFixture()));
        self::assertSame([
            'imported' => 0,
            'duplicates' => 4,
            'failed' => 0,
            'categories_created' => 0,
        ], $this->decode($duplicate)['data']);
    }

    public function testImportKeepsSuccessfulEntriesWhenOthersAreInvalid(): void
    {
        [$kernel, $csrf] = $this->authenticatedKernel('alice', 'correct horse battery staple');
        $longCategory = str_repeat('x', 201);
        $xml = '<?xml version="1.0"?><opml version="2.0"><body>'
            . '<outline text="' . $longCategory . '">'
            . '<outline text="Catégorie invalide" xmlUrl="https://example.org/invalid-category.xml" />'
            . '</outline>'
            . '<outline text="Schéma interdit" xmlUrl="file:///etc/passwd" />'
            . '<outline text="Valide" xmlUrl="https://example.org/valid.xml" />'
            . '</body></opml>';

        $response = $kernel->handle($this->importRequest($csrf, $this->uploadedXml($xml)));

        self::assertSame(200, $response->status);
        self::assertSame([
            'imported' => 1,
            'duplicates' => 0,
            'failed' => 2,
            'categories_created' => 0,
        ], $this->decode($response)['data']);
    }

    public function testImportRejectsMissingOversizedMalformedAndUnsafeFiles(): void
    {
        [$kernel, $csrf] = $this->authenticatedKernel('alice', 'correct horse battery staple');

        self::assertSame(422, $kernel->handle(new Request(
            'POST',
            '/api/opml/import',
            ['content-type' => 'multipart/form-data; boundary=test', 'x-csrf-token' => $csrf],
        ))->status);
        self::assertSame(422, $kernel->handle($this->importRequest(
            $csrf,
            $this->uploadedXml('<opml><body><outline></body>'),
        ))->status);
        $unsafe = '<?xml version="1.0"?><!DOCTYPE opml ['
            . '<!ENTITY secret SYSTEM "file:///etc/passwd">]>'
            . '<opml version="2.0"><body><outline text="&secret;" /></body></opml>';
        $unsafeResponse = $kernel->handle($this->importRequest($csrf, $this->uploadedXml($unsafe)));
        self::assertSame(422, $unsafeResponse->status);
        self::assertStringNotContainsString('root:', $unsafeResponse->body);

        $oversized = $this->uploadedXml(str_repeat('x', 1_048_577));
        self::assertSame(413, $kernel->handle($this->importRequest($csrf, $oversized))->status);
        self::assertSame(422, $kernel->handle(new Request(
            'POST',
            '/api/opml/import',
            ['content-type' => 'application/xml', 'x-csrf-token' => $csrf],
        ))->status);
    }

    public function testExportIsValidIsolatedAndCanBeImportedByAnotherUser(): void
    {
        [$aliceKernel, $aliceCsrf, $aliceId] = $this->authenticatedKernel(
            'alice',
            'correct horse battery staple',
        );
        [$bobKernel, $bobCsrf, $bobId] = $this->authenticatedKernel('bob', 'another correct horse battery');
        $aliceKernel->handle($this->importRequest($aliceCsrf, $this->uploadedFixture()));
        $this->insertBobOnlyFeed($bobId);

        $export = $aliceKernel->handle(new Request('GET', '/api/opml/export'));
        self::assertSame(200, $export->status);
        self::assertSame('application/xml; charset=utf-8', $export->headers['Content-Type']);
        self::assertSame('attachment; filename="rss-reader.opml"', $export->headers['Content-Disposition']);
        self::assertStringNotContainsString('bob-only.xml', $export->body);
        $document = new DOMDocument();
        self::assertTrue($document->loadXML($export->body, LIBXML_NONET));
        self::assertSame('opml', $document->documentElement?->localName);

        $roundTrip = $bobKernel->handle($this->importRequest($bobCsrf, $this->uploadedXml($export->body)));
        self::assertSame(4, $this->decode($roundTrip)['data']['imported']);
        $bobStructure = array_values(array_filter(
            $this->feedStructure($bobId),
            static fn(array $feed): bool => $feed['feed_url'] !== 'https://example.net/bob-only.xml',
        ));
        self::assertSame($this->feedStructure($aliceId), $bobStructure);
        self::assertSame($this->categoryNames($aliceId), $this->categoryNames($bobId));
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

    private function importRequest(string $csrf, UploadedFile $file): Request
    {
        return new Request(
            'POST',
            '/api/opml/import',
            ['content-type' => 'multipart/form-data; boundary=test', 'x-csrf-token' => $csrf],
            files: ['file' => $file],
        );
    }

    private function uploadedFixture(): UploadedFile
    {
        $size = filesize($this->fixture);
        self::assertIsInt($size);

        return new UploadedFile($this->fixture, $size, UPLOAD_ERR_OK);
    }

    private function uploadedXml(string $xml): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'rss-reader-opml-');
        self::assertIsString($path);
        self::assertSame(strlen($xml), file_put_contents($path, $xml));
        $this->temporaryFiles[] = $path;

        return new UploadedFile($path, strlen($xml), UPLOAD_ERR_OK);
    }

    /** @return list<string> */
    private function categoryNames(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT name FROM categories WHERE user_id = :user_id ORDER BY name COLLATE NOCASE'
        );
        $statement->execute(['user_id' => $userId]);

        return array_values(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    /** @return list<array{feed_url: string, category_name: string|null}> */
    private function feedStructure(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT f.feed_url, c.name AS category_name FROM feeds f '
            . 'LEFT JOIN categories c ON c.id = f.category_id AND c.user_id = f.user_id '
            . 'WHERE f.user_id = :user_id ORDER BY f.feed_url'
        );
        $statement->execute(['user_id' => $userId]);
        $structure = [];
        while (($row = $statement->fetch()) !== false) {
            if (is_array($row)) {
                $structure[] = [
                    'feed_url' => (string) $row['feed_url'],
                    'category_name' => $row['category_name'] === null ? null : (string) $row['category_name'],
                ];
            }
        }

        return $structure;
    }

    private function insertBobOnlyFeed(int $bobId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO feeds '
            . '(user_id, name, feed_url, is_active, last_fetch_status, created_at, updated_at) '
            . 'VALUES (:user_id, :name, :feed_url, 1, :status, :created_at, :updated_at)'
        );
        $statement->execute([
            'user_id' => $bobId,
            'name' => 'Bob seulement',
            'feed_url' => 'https://example.net/bob-only.xml',
            'status' => 'never',
            'created_at' => '2026-09-24T12:00:00Z',
            'updated_at' => '2026-09-24T12:00:00Z',
        ]);
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        $decoded = json_decode($response->body, true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
