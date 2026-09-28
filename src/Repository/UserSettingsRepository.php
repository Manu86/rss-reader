<?php

declare(strict_types=1);

namespace App\Repository;

use App\Model\UserSettings;
use PDO;

final readonly class UserSettingsRepository
{
    public function __construct(private PDO $pdo) {}

    public function articlesPerPage(int $userId): int
    {
        $settings = $this->findOwned($userId);

        return $settings === null ? UserSettings::DEFAULT_PAGE_SIZE : $settings->articlesPerPage;
    }

    public function findOwned(int $userId): ?UserSettings
    {
        $statement = $this->pdo->prepare(
            'SELECT articles_per_page, theme, recommendation_email_frequency '
            . 'FROM user_settings WHERE user_id = :user_id'
        );
        $statement->execute(['user_id' => $userId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }

        $pageSize = (int) $row['articles_per_page'];
        if (!in_array($pageSize, UserSettings::ALLOWED_PAGE_SIZES, true)) {
            return null;
        }

        $theme = (string) $row['theme'];
        $theme = in_array($theme, [UserSettings::DEFAULT_THEME, 'dark'], true)
            ? $theme
            : UserSettings::DEFAULT_THEME;

        $frequency = (string) $row['recommendation_email_frequency'];
        $frequency = in_array($frequency, UserSettings::ALLOWED_RECOMMENDATION_EMAIL_FREQUENCIES, true)
            ? $frequency
            : UserSettings::DEFAULT_RECOMMENDATION_EMAIL_FREQUENCY;

        return new UserSettings($userId, $pageSize, $theme, $frequency);
    }

    public function updateArticlePreferences(
        int $userId,
        ?int $articlesPerPage,
        ?string $theme,
        string $now,
    ): ?UserSettings {
        $statement = $this->pdo->prepare(
            'UPDATE user_settings SET '
            . 'articles_per_page = COALESCE(:articles_per_page, articles_per_page), '
            . 'theme = COALESCE(:theme, theme), '
            . 'updated_at = :updated_at '
            . 'WHERE user_id = :user_id'
        );
        $statement->execute([
            'articles_per_page' => $articlesPerPage,
            'theme' => $theme,
            'updated_at' => $now,
            'user_id' => $userId,
        ]);

        return $this->findOwned($userId);
    }
}
