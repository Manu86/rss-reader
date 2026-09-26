<?php

declare(strict_types=1);

namespace App;

use App\Clock\SystemClock;
use App\Config\AppConfig;
use App\Console\ConsoleApplication;
use App\Console\FileProcessLock;
use App\Console\PasswordReader;
use App\Controller\ArticleController;
use App\Controller\AuthController;
use App\Controller\CategoryController;
use App\Controller\FeedController;
use App\Controller\FeedDiscoveryController;
use App\Controller\MediaController;
use App\Controller\OpmlController;
use App\Controller\SettingsController;
use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\Database\TransactionManager;
use App\Http\ApiKernel;
use App\Http\SafeHttpClientFactory;
use App\Http\UrlResolver;
use App\Repository\ArticleCountRepository;
use App\Repository\ArticleRepository;
use App\Repository\ArticleRetentionRepository;
use App\Repository\CategoryRepository;
use App\Repository\FeedRepository;
use App\Repository\LoginAttemptRepository;
use App\Repository\MediaReferenceRepository;
use App\Repository\RemoteActionAttemptRepository;
use App\Repository\UserRepository;
use App\Repository\UserSettingsRepository;
use App\Security\CsrfTokenManager;
use App\Security\CurrentUser;
use App\Security\LoginRateLimiter;
use App\Security\NativeSession;
use App\Security\PasswordPolicy;
use App\Security\RemoteActionRateLimiter;
use App\Service\ArticleRetentionService;
use App\Service\ArticleService;
use App\Service\AuthenticationService;
use App\Service\AutomaticFeedRefreshService;
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
use App\Service\InstallationService;
use App\Service\MediaCleanupService;
use App\Service\OpmlExporter;
use App\Service\OpmlImportService;
use App\Service\OpmlParser;
use App\Service\RemoteMediaService;
use App\Service\UserService;
use App\Service\UserSettingsService;
use App\Storage\FileMediaStorage;
use App\Validation\UrlNormalizer;

final class ApplicationFactory
{
    public static function createConsole(string $projectRoot): ConsoleApplication
    {
        $config = AppConfig::load($projectRoot . '/config/app.php');
        $pdo = ConnectionFactory::create($config->databasePath);
        $clock = new SystemClock();
        $users = new UserRepository($pdo);
        $feeds = new FeedRepository($pdo);
        $articles = new ArticleRepository($pdo);
        $urlResolver = new UrlResolver();
        $urlNormalizer = new UrlNormalizer();
        $httpClient = SafeHttpClientFactory::create($config);
        $mediaStorage = new FileMediaStorage($config->mediaPath);
        $synchronization = new FeedSynchronizationService(
            $feeds,
            $articles,
            new FeedFetcher($httpClient, new FeedParser(
                $urlResolver,
                $urlNormalizer,
                new ExternalHtmlTextSanitizer(),
                $clock,
            )),
            new TransactionManager($pdo),
            $clock,
            new RemoteMediaService(
                $httpClient,
                $mediaStorage,
                $config->mediaMaxBytes,
                $config->mediaMaxWidth,
                $config->mediaMaxHeight,
                $config->mediaMaxPixels,
            ),
        );
        $migrator = new Migrator($pdo, $projectRoot . '/migrations');
        $mediaCleanup = new MediaCleanupService(new MediaReferenceRepository($pdo), $mediaStorage);
        $articleRetention = new ArticleRetentionService(
            new ArticleRetentionRepository($pdo),
            $mediaCleanup,
            $clock,
        );

        return new ConsoleApplication(
            $migrator,
            new InstallationService($config, $migrator),
            $users,
            new UserService($users, new PasswordPolicy(), $clock),
            new PasswordReader(),
            new AutomaticFeedRefreshService($feeds, $synchronization),
            $articleRetention,
            new FileProcessLock($config->cronLockPath),
            $articles,
        );
    }

    public static function createWeb(string $projectRoot): ApiKernel
    {
        $config = AppConfig::load($projectRoot . '/config/app.php');
        $pdo = ConnectionFactory::create($config->databasePath);
        $clock = new SystemClock();
        $users = new UserRepository($pdo);
        $session = new NativeSession(
            $config->sessionName,
            $config->sessionLifetime,
            $config->sessionSecure,
        );
        $csrf = new CsrfTokenManager($session);
        $rateLimiter = new LoginRateLimiter(
            new LoginAttemptRepository($pdo),
            $clock,
            $config->readSecret(),
        );
        $authentication = new AuthenticationService($users, $rateLimiter);
        $currentUser = new CurrentUser($session, $authentication);
        $userService = new UserService($users, new PasswordPolicy(), $clock);
        $authController = new AuthController(
            $authentication,
            $userService,
            $session,
            $csrf,
            $currentUser,
        );
        $httpClient = SafeHttpClientFactory::create($config);
        $feedRepository = new FeedRepository($pdo);
        $articleRepository = new ArticleRepository($pdo);
        $categoryRepository = new CategoryRepository($pdo);
        $remoteActionLimiter = new RemoteActionRateLimiter(
            new RemoteActionAttemptRepository($pdo),
            $clock,
        );
        $urlResolver = new UrlResolver();
        $urlNormalizer = new UrlNormalizer();
        $mediaStorage = new FileMediaStorage($config->mediaPath);
        $remoteMedia = new RemoteMediaService(
            $httpClient,
            $mediaStorage,
            $config->mediaMaxBytes,
            $config->mediaMaxWidth,
            $config->mediaMaxHeight,
            $config->mediaMaxPixels,
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
            new TransactionManager($pdo),
            $clock,
            $remoteMedia,
        );
        $feedService = new FeedService(
            $feedRepository,
            $categoryRepository,
            $urlNormalizer,
            $clock,
            $feedSynchronization,
            $remoteActionLimiter,
            new MediaCleanupService(new MediaReferenceRepository($pdo), $mediaStorage),
        );
        $feedController = new FeedController($feedService, $currentUser);
        $categoryService = new CategoryService($categoryRepository, $clock);
        $categoryController = new CategoryController($categoryService, $currentUser);
        $articleController = new ArticleController(new ArticleService(
            $articleRepository,
            $feedRepository,
            $categoryRepository,
            new UserSettingsRepository($pdo),
            new ArticleCountRepository($pdo),
            new FtsQueryBuilder(),
            $clock,
        ), $currentUser);
        $feedDiscoveryController = new FeedDiscoveryController(new FeedDiscoveryService(
            $httpClient,
            new FeedDocumentDetector(),
            new HtmlFeedDiscoveryParser($urlResolver),
            $remoteActionLimiter,
        ), $currentUser);

        return new ApiKernel(
            $authController,
            $categoryController,
            $articleController,
            $feedController,
            $feedDiscoveryController,
            new MediaController($feedRepository, $articleRepository, $mediaStorage, $currentUser),
            new SettingsController(new UserSettingsService(
                new UserSettingsRepository($pdo),
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
        );
    }
}
