<?php

declare(strict_types=1);

namespace App\Repository;

use App\Model\RecommendationEmailRecipient;
use PDO;

final readonly class RecommendationEmailRepository
{
    public function __construct(private PDO $pdo) {}

    /** @return list<RecommendationEmailRecipient> */
    public function listEnabledRecipients(): array
    {
        $statement = $this->pdo->query(
            'SELECT u.id, u.username, u.email, us.recommendation_email_frequency, '
            . 'us.recommendation_email_last_sent_at '
            . 'FROM users u INNER JOIN user_settings us ON us.user_id = u.id '
            . "WHERE u.is_active = 1 AND u.email IS NOT NULL AND u.email <> '' "
            . "AND us.recommendation_email_frequency <> 'never' ORDER BY u.id"
        );
        if ($statement === false) {
            return [];
        }

        $recipients = [];
        while (($row = $statement->fetch()) !== false) {
            if (!is_array($row)) {
                continue;
            }
            $recipients[] = new RecommendationEmailRecipient(
                (int) $row['id'],
                (string) $row['username'],
                (string) $row['email'],
                (string) $row['recommendation_email_frequency'],
                $row['recommendation_email_last_sent_at'] === null
                    ? null
                    : (string) $row['recommendation_email_last_sent_at'],
            );
        }

        return $recipients;
    }

    public function markSent(int $userId, string $sentAt): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE user_settings SET recommendation_email_last_sent_at = :sent_at, '
            . 'updated_at = :sent_at WHERE user_id = :user_id'
        );
        $statement->execute(['sent_at' => $sentAt, 'user_id' => $userId]);
    }
}
