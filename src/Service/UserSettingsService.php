<?php

declare(strict_types=1);

namespace App\Service;

use App\Clock\Clock;
use App\Exception\ValidationException;
use App\Model\UserSettings;
use App\Repository\UserSettingsRepository;

final readonly class UserSettingsService
{
    public function __construct(
        private UserSettingsRepository $settings,
        private Clock $clock,
    ) {}

    public function get(int $userId): UserSettings
    {
        $settings = $this->settings->findOwned($userId);
        if ($settings === null) {
            throw new \RuntimeException('Les paramètres utilisateur sont introuvables.');
        }

        return $settings;
    }

    public function update(int $userId, ?int $articlesPerPage, ?string $theme): UserSettings
    {
        $fields = [];
        if ($articlesPerPage !== null
            && !in_array($articlesPerPage, UserSettings::ALLOWED_PAGE_SIZES, true)) {
            $fields['articles_per_page'] = 'La valeur doit être 10, 25, 50 ou 100.';
        }
        if ($theme !== null && !in_array($theme, UserSettings::ALLOWED_THEMES, true)) {
            $fields['theme'] = 'Le thème doit être light ou dark.';
        }
        if ($fields !== []) {
            throw new ValidationException($fields);
        }

        $settings = $this->settings->updateArticlePreferences(
            $userId,
            $articlesPerPage,
            $theme,
            $this->clock->now()->format('Y-m-d\TH:i:s\Z'),
        );
        if ($settings === null) {
            throw new \RuntimeException('Les paramètres utilisateur sont introuvables.');
        }

        return $settings;
    }
}
