<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Controller\ArticleController;
use App\Controller\AuthController;
use App\Controller\CategoryController;
use App\Controller\FeedController;
use App\Controller\FeedDiscoveryController;
use App\Controller\MediaController;
use App\Controller\OpmlController;
use App\Controller\RecommendationController;
use App\Controller\SettingsController;
use App\Database\Migrator;
use App\Database\TransactionManager;
use App\Http\ApiKernel;
use App\Http\SafeHttpClient;
use App\Http\TransportResponse;
use App\Http\UrlResolver;
use App\Repository\ArticleCountRepository;
use App\Repository\ArticleRepository;
use App\Repository\CategoryRepository;
use App\Repository\FeedRepository;
use App\Repository\LoginAttemptRepository;
use App\Repository\MediaReferenceRepository;
use App\Repository\RememberTokenRepository;
use App\Repository\RemoteActionAttemptRepository;
use App\Repository\UserRepository;
use App\Repository\UserSettingsRepository;
use App\Security\CsrfTokenManager;
use App\Security\CurrentUser;
use App\Security\IpAddressValidator;
use App\Security\LoginRateLimiter;
use App\Security\PasswordPolicy;
use App\Security\RemoteActionRateLimiter;
use App\Security\RemoteUrlGuard;
use App\Service\ArticleService;
use App\Service\AuthenticationService;
use App\Service\CategoryService;
use App\Service\ExternalHtmlTextSanitizer;
use App\Service\FeedDiscoveryService;
use App\Service\FeedDocumentDetector;
use App\Service\FeedFetcher;
use App\Service\FeedParser;
use App\Service\FeedService;
use App\Service\FeedSynchronizationService;
use App\Service\FtsQueryBuilder;
use App\Service\HtmlFeedDiscoveryParser;
use App\Service\MediaCleanupService;
use App\Service\OpmlExporter;
use App\Service\OpmlImportService;
use App\Service\OpmlParser;
use App\Service\RecommendationService;
use App\Service\RememberTokenService;
use App\Service\RemoteMediaService;
use App\Service\UserService;
use App\Service\UserSettingsService;
use App\Validation\UrlNormalizer;
use DateTimeImmutable;
use PDO;

final readonly class TestApplication
{
    public UserRepository $users;
    public UserService $userService;
    public MemoryMediaStorage $mediaStorage;

    public function __construct(
        private PDO $pdo,
        private string $migrationDirectory,
    ) {
        (new Migrator($this->pdo, $this->migrationDirectory))->migrate();
        $clock = new FrozenClock(new DateTimeImmutable('2026-09-24T12:00:00Z'));
        $this->users = new UserRepository($this->pdo);
        $this->userService = new UserService($this->users, new PasswordPolicy(), $clock);
        $this->mediaStorage = new MemoryMediaStorage();
    }

    public function kernel(ArraySession $session, ?SafeHttpClient $httpClient = null): ApiKernel
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-09-24T12:00:00Z'));
        $csrf = new CsrfTokenManager($session);
        $rateLimiter = new LoginRateLimiter(
            new LoginAttemptRepository($this->pdo),
            $clock,
            'test-secret-with-at-least-thirty-two-characters',
        );
        $authentication = new AuthenticationService($this->users, $rateLimiter);
        $currentUser = new CurrentUser($session, $authentication);
        $rememberTokens = new RememberTokenService(
            new RememberTokenRepository($this->pdo),
            $authentication,
            $clock,
            2_592_000,
            'rss_reader_remember',
            false,
        );
        $authController = new AuthController(
            $authentication,
            $this->userService,
            $session,
            $csrf,
            $currentUser,
            $rememberTokens,
        );
        $httpClient ??= new SafeHttpClient(
            new RemoteUrlGuard(new UrlNormalizer(), new FakeDnsResolver([
                'example.org' => ['93.184.216.34'],
                'example.net' => ['93.184.216.35'],
            ]), new IpAddressValidator()),
            new FakeHttpTransport(array_fill(0, 20, new TransportResponse(
                200,
                ['content-type' => 'application/rss+xml'],
                '<?xml version="1.0"?><rss version="2.0"><channel>'
                    . '<title>Flux de test</title><link>https://example.org/</link>'
                    . '</channel></rss>',
            ))),
            new UrlResolver(),
            100,
            500,
            1_000_000,
            3,
            'RSSReader/Test',
        );
        $feedRepository = new FeedRepository($this->pdo);
        $articleRepository = new ArticleRepository($this->pdo);
        $categoryRepository = new CategoryRepository($this->pdo);
        $remoteActionLimiter = new RemoteActionRateLimiter(
            new RemoteActionAttemptRepository($this->pdo),
            $clock,
        );
        $urlResolver = new UrlResolver();
        $urlNormalizer = new UrlNormalizer();
        $remoteMedia = new RemoteMediaService(
            $httpClient,
            $this->mediaStorage,
            1_000_000,
            4096,
            4096,
            16_777_216,
        );
        $feedSynchronization = new FeedSynchronizationService(
            $feedRepository,
            $articleRepository,
            new FeedFetcher($httpClient, new FeedParser(
                $urlResolver,
                $urlNormalizer,
                new ExternalHtmlTextSanitizer(),
                $clock,
            )),
            new TransactionManager($this->pdo),
            $clock,
            $remoteMedia,
        );
        $feedController = new FeedController(new FeedService(
            $feedRepository,
            $categoryRepository,
            $urlNormalizer,
            $clock,
            $feedSynchronization,
            $remoteActionLimiter,
            new MediaCleanupService(new MediaReferenceRepository($this->pdo), $this->mediaStorage),
        ), $currentUser);
        $categoryService = new CategoryService($categoryRepository, $clock);
        $feedDiscoveryController = new FeedDiscoveryController(new FeedDiscoveryService(
            $httpClient,
            new FeedDocumentDetector(),
            new HtmlFeedDiscoveryParser($urlResolver),
            $remoteActionLimiter,
        ), $currentUser);

        return new ApiKernel(
            $authController,
            new CategoryController($categoryService, $currentUser),
            new ArticleController(new ArticleService(
                $articleRepository,
                $feedRepository,
                $categoryRepository,
                new ArticleCountRepository($this->pdo),
                new FtsQueryBuilder(),
                $clock,
            ), $currentUser),
            new RecommendationController(new RecommendationService($articleRepository, $categoryRepository), $currentUser),
            $feedController,
            $feedDiscoveryController,
            new MediaController($feedRepository, $articleRepository, $this->mediaStorage, $currentUser),
            new SettingsController(new UserSettingsService(
                new UserSettingsRepository($this->pdo),
                $clock,
            ), $currentUser),
            new OpmlController(
                new OpmlImportService(
                    new OpmlParser(),
                    $categoryService,
                    $feedRepository,
                    $feedSynchronization,
                    $urlNormalizer,
                    $remoteActionLimiter,
                ),
                new OpmlExporter($categoryRepository, $feedRepository, $clock),
                $currentUser,
            ),
            $csrf,
            $currentUser,
            $rememberTokens,
        );
    }
}
