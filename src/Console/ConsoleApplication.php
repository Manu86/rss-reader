<?php

declare(strict_types=1);

namespace App\Console;

use App\Database\Migrator;
use App\Exception\ValidationException;
use App\Repository\ArticleRepository;
use App\Repository\UserRepository;
use App\Service\ArticleContentEnrichmentService;
use App\Service\ArticleRetentionService;
use App\Service\AutomaticFeedRefreshService;
use App\Service\InstallationService;
use App\Service\RecommendationDigestService;
use App\Service\UserService;
use Throwable;

final readonly class ConsoleApplication
{
    public function __construct(
        private Migrator $migrator,
        private InstallationService $installation,
        private UserRepository $users,
        private UserService $userService,
        private PasswordReader $passwordReader,
        private AutomaticFeedRefreshService $automaticFeedRefresh,
        private RecommendationDigestService $recommendationDigests,
        private ArticleRetentionService $articleRetention,
        private ProcessLock $maintenanceLock,
        private ArticleRepository $articles,
        private ArticleContentEnrichmentService $articleContentEnrichment,
    ) {}

    /** @param list<string> $arguments */
    public function run(array $arguments): int
    {
        $command = $arguments[1] ?? 'help';
        try {
            return match ($command) {
                'app:install' => $this->install($arguments),
                'db:migrate' => $this->migrate(),
                'user:create' => $this->createUser($arguments),
                'user:list' => $this->listUsers(),
                'user:password' => $this->changePassword($arguments),
                'user:enable' => $this->setUserActive($arguments, true),
                'user:disable' => $this->setUserActive($arguments, false),
                'feeds:refresh' => $this->refreshFeeds(),
                'articles:cleanup' => $this->cleanupArticles(),
                'articles:enrich-content' => $this->enrichArticleContent(),
                'fts:rebuild' => $this->rebuildSearchIndex(),
                'help', '--help', '-h' => $this->help(),
                default => $this->unknownCommand($command),
            };
        } catch (ValidationException $exception) {
            fwrite(STDERR, $exception->getMessage() . PHP_EOL);
            foreach ($exception->fields as $field => $message) {
                fwrite(STDERR, sprintf('  %s: %s%s', $field, $message, PHP_EOL));
            }

            return 2;
        } catch (Throwable $exception) {
            fwrite(STDERR, 'Erreur : ' . $exception->getMessage() . PHP_EOL);

            return 1;
        }
    }

    /** @param list<string> $arguments */
    private function install(array $arguments): int
    {
        $username = $arguments[2] ?? null;
        if (!is_string($username) || str_starts_with($username, '--')) {
            fwrite(STDERR, 'Usage : app:install <utilisateur> [--password-stdin]' . PHP_EOL);

            return 2;
        }

        $migrations = $this->installation->prepare();
        if ($this->users->count() !== 0) {
            fwrite(STDERR, 'L’installation contient déjà un compte utilisateur.' . PHP_EOL);

            return 1;
        }
        $password = $this->passwordReader->read(in_array('--password-stdin', $arguments, true));
        $user = $this->userService->create($username, $password);
        fwrite(STDOUT, sprintf(
            'Installation terminée. Utilisateur %s créé (id %d), %d migration(s) appliquée(s).%s',
            $user->username,
            $user->id,
            count($migrations),
            PHP_EOL,
        ));

        return 0;
    }

    private function migrate(): int
    {
        $migrations = $this->migrator->migrate();
        fwrite(STDOUT, sprintf('%d migration(s) appliquée(s).%s', count($migrations), PHP_EOL));

        return 0;
    }

    /** @param list<string> $arguments */
    private function createUser(array $arguments): int
    {
        $username = $arguments[2] ?? null;
        if (!is_string($username) || str_starts_with($username, '--')) {
            fwrite(STDERR, 'Usage : user:create <utilisateur> [--password-stdin]' . PHP_EOL);

            return 2;
        }
        $password = $this->passwordReader->read(in_array('--password-stdin', $arguments, true));
        $user = $this->userService->create($username, $password);
        fwrite(STDOUT, sprintf('Utilisateur %s créé (id %d).%s', $user->username, $user->id, PHP_EOL));

        return 0;
    }

    private function listUsers(): int
    {
        foreach ($this->users->listAll() as $user) {
            fwrite(STDOUT, sprintf(
                "%d\t%s\t%s\t%s%s",
                $user['id'],
                $user['username'],
                $user['is_active'] ? 'actif' : 'désactivé',
                $user['created_at'],
                PHP_EOL,
            ));
        }

        return 0;
    }

    /** @param list<string> $arguments */
    private function changePassword(array $arguments): int
    {
        $username = $arguments[2] ?? null;
        if (!is_string($username) || str_starts_with($username, '--')) {
            fwrite(STDERR, 'Usage : user:password <utilisateur> [--password-stdin]' . PHP_EOL);

            return 2;
        }
        $password = $this->passwordReader->read(in_array('--password-stdin', $arguments, true));
        if (!$this->userService->resetPassword($username, $password)) {
            fwrite(STDERR, 'Utilisateur introuvable.' . PHP_EOL);

            return 1;
        }
        fwrite(STDOUT, 'Mot de passe mis à jour.' . PHP_EOL);

        return 0;
    }

    /** @param list<string> $arguments */
    private function setUserActive(array $arguments, bool $active): int
    {
        $username = $arguments[2] ?? null;
        if (!is_string($username)) {
            fwrite(STDERR, sprintf('Usage : user:%s <utilisateur>%s', $active ? 'enable' : 'disable', PHP_EOL));

            return 2;
        }
        if (!$this->userService->setActive($username, $active)) {
            fwrite(STDERR, 'Utilisateur introuvable.' . PHP_EOL);

            return 1;
        }
        fwrite(STDOUT, sprintf('Utilisateur %s.%s', $active ? 'activé' : 'désactivé', PHP_EOL));

        return 0;
    }

    private function help(): int
    {
        fwrite(
            STDOUT,
            <<<'HELP'
Commandes disponibles :
  app:install <utilisateur> [--password-stdin]
  db:migrate
  user:create <utilisateur> [--password-stdin]
  user:list
  user:password <utilisateur> [--password-stdin]
  user:enable <utilisateur>
  user:disable <utilisateur>
  feeds:refresh
  articles:cleanup
  articles:enrich-content
  fts:rebuild
HELP
        );
        fwrite(STDOUT, PHP_EOL);

        return 0;
    }

    private function refreshFeeds(): int
    {
        if (!$this->maintenanceLock->acquire()) {
            fwrite(STDERR, 'Une tâche de maintenance est déjà en cours.' . PHP_EOL);

            return 75;
        }

        try {
            $summary = $this->automaticFeedRefresh->run();
            $deletedArticles = $this->articleRetention->cleanup();
            $digestSummary = $this->recommendationDigests->run();
        } finally {
            $this->maintenanceLock->release();
        }
        fwrite(STDOUT, sprintf(
            'Synchronisation terminée : %d flux, %d succès, %d ignoré(s), %d échec(s), '
            . '%d article(s) importé(s), %d inchangé(s), %d ancien(s) article(s) supprimé(s).%s',
            $summary['total'],
            $summary['successful'],
            $summary['skipped'],
            $summary['failed'],
            $summary['imported_articles'],
            $summary['not_modified'],
            $deletedArticles,
            PHP_EOL,
        ));
        if ($digestSummary['configured']) {
            fwrite(STDOUT, sprintf(
                'Recommandations par email : %d destinataire(s), %d dû(s), %d envoyé(s), '
                . '%d sans recommandation, %d échec(s).%s',
                $digestSummary['total'],
                $digestSummary['due'],
                $digestSummary['sent'],
                $digestSummary['empty'],
                $digestSummary['failed'],
                PHP_EOL,
            ));
        } else {
            fwrite(STDOUT, 'Recommandations par email : transport non configuré.' . PHP_EOL);
        }

        // Un flux ignore est deja en cours d'actualisation ailleurs : le cron
        // reste un succes tant qu'aucun flux n'a echoue.
        return $summary['failed'] === 0 && $digestSummary['failed'] === 0 ? 0 : 1;
    }

    private function cleanupArticles(): int
    {
        if (!$this->maintenanceLock->acquire()) {
            fwrite(STDERR, 'Une tâche de maintenance est déjà en cours.' . PHP_EOL);

            return 75;
        }

        try {
            $deletedArticles = $this->articleRetention->cleanup();
        } finally {
            $this->maintenanceLock->release();
        }
        fwrite(STDOUT, sprintf(
            'Nettoyage terminé : %d ancien(s) article(s) supprimé(s).%s',
            $deletedArticles,
            PHP_EOL,
        ));

        return 0;
    }

    private function rebuildSearchIndex(): int
    {
        $this->articles->rebuildSearchIndex();
        fwrite(STDOUT, 'Index de recherche reconstruit.' . PHP_EOL);

        return 0;
    }

    private function enrichArticleContent(): int
    {
        if (!$this->maintenanceLock->acquire()) {
            fwrite(STDERR, 'Une tâche de maintenance est déjà en cours.' . PHP_EOL);

            return 75;
        }

        try {
            $summary = $this->articleContentEnrichment->run(static function (int $done, int $total): void {
                if ($done % 25 === 0 || $done === $total) {
                    fwrite(STDOUT, sprintf("Progression : %d/%d%s", $done, $total, PHP_EOL));
                }
            });
        } finally {
            $this->maintenanceLock->release();
        }
        fwrite(STDOUT, sprintf(
            'Enrichissement terminé : %d contenu(s) réparé(s), %d candidat(s), %d contenu(s) extrait(s), '
            . '%d page(s) sans contenu, %d échec(s).%s',
            $summary['repaired'],
            $summary['total'],
            $summary['extracted'],
            $summary['empty'],
            $summary['failed'],
            PHP_EOL,
        ));

        return $summary['failed'] === 0 ? 0 : 1;
    }

    private function unknownCommand(string $command): int
    {
        fwrite(STDERR, sprintf('Commande inconnue : %s%s', $command, PHP_EOL));

        return 2;
    }
}
