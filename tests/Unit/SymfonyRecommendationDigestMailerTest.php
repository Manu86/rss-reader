<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Model\Article;
use App\Model\RecommendationEmailRecipient;
use App\Model\StoredMedia;
use App\Service\RecommendationImageProvider;
use App\Service\SymfonyRecommendationDigestMailer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

final class SymfonyRecommendationDigestMailerTest extends TestCase
{
    public function testDigestContainsSafeApplicationLinksAndNoRemoteImages(): void
    {
        $transport = new class implements MailerInterface {
            public ?RawMessage $message = null;

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                $this->message = $message;
            }
        };
        $mailer = new SymfonyRecommendationDigestMailer(
            $transport,
            new Address('reader@example.org', 'RSS Reader'),
            'https://reader.example.org/',
        );
        $recipient = new RecommendationEmailRecipient(1, 'Alice', 'alice@example.org', 'daily', null);
        $article = new Article(
            42,
            10,
            'Source <test>',
            false,
            3,
            'Sécurité & veille',
            '<script>alert(1)</script>',
            'https://remote.example.org/article',
            null,
            null,
            '2026-09-28T05:00:00Z',
            null,
            null,
        );

        $mailer->send($recipient, [$article]);

        self::assertInstanceOf(Email::class, $transport->message);
        $html = $transport->message->getHtmlBody();
        $text = $transport->message->getTextBody();
        self::assertIsString($html);
        self::assertIsString($text);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringContainsString('https://reader.example.org/#/articles/42', $html);
        self::assertStringContainsString('Recommandé pour vous', $html);
        self::assertStringContainsString('<meta name="color-scheme" content="light dark">', $html);
        self::assertStringContainsString('.recommendation-title-link:hover', $html);
        self::assertStringContainsString('text-decoration:underline!important', $html);
        self::assertStringContainsString('.recommendation-card{background:#202830!important;border-color:#303a43!important;}', $html);
        self::assertStringContainsString('28 sept. 2026', $html);
        self::assertStringContainsString('Sécurité &amp; veille', $html);
        self::assertStringContainsString('background:#e5f1fb', $html);
        self::assertStringContainsString('Ouvrir mes recommandations', $html);
        self::assertStringContainsString('cid:rss-reader-default-placeholder.png', $html);
        self::assertStringNotContainsString('https://remote.example.org/article', $html);
        self::assertStringContainsString('https://reader.example.org/#/parametres', $text);
    }

    public function testDigestEmbedsStoredArticleImagesWithoutRemoteRequest(): void
    {
        $transport = new class implements MailerInterface {
            public ?RawMessage $message = null;

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                $this->message = $message;
            }
        };
        $images = new class implements RecommendationImageProvider {
            public int $requestedUserId = 0;

            public function forArticle(int $userId, Article $article): ?StoredMedia
            {
                $this->requestedUserId = $userId;

                return $article->hasImage ? new StoredMedia('local-image', 'image/jpeg') : null;
            }
        };
        $mailer = new SymfonyRecommendationDigestMailer(
            $transport,
            new Address('reader@example.org', 'RSS Reader'),
            'https://reader.example.org',
            $images,
        );
        $article = new Article(
            42,
            10,
            'Source',
            false,
            null,
            null,
            'Article illustré',
            null,
            null,
            null,
            '2026-09-28T05:00:00Z',
            null,
            null,
            [],
            true,
        );

        $mailer->send(
            new RecommendationEmailRecipient(7, 'Alice', 'alice@example.org', 'daily', null),
            [$article],
        );

        self::assertInstanceOf(Email::class, $transport->message);
        self::assertSame(7, $images->requestedUserId);
        self::assertStringContainsString('cid:recommendation-42.jpg', (string) $transport->message->getHtmlBody());
        self::assertCount(1, $transport->message->getAttachments());
        self::assertSame('image/jpeg', $transport->message->getAttachments()[0]->getContentType());
        $serialized = $transport->message->toString();
        self::assertStringContainsString('Content-Type: image/jpeg; name=recommendation-42.jpg', $serialized);
        self::assertStringContainsString('Content-Disposition: inline;', $serialized);
        self::assertMatchesRegularExpression('/<img src=3D"cid:[^\"]+/', $serialized);
    }

    public function testImageBeyondMimeBudgetFallsBackToEmbeddedPlaceholder(): void
    {
        $transport = new class implements MailerInterface {
            public ?RawMessage $message = null;

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                $this->message = $message;
            }
        };
        // Une image dont l'encodage MIME dépasse le budget global : l'article
        // doit référencer le pictogramme RSS, lui aussi réellement embarqué.
        $images = new class implements RecommendationImageProvider {
            public function forArticle(int $userId, Article $article): StoredMedia
            {
                unset($userId, $article);

                return new StoredMedia(str_repeat('x', 1_600_000), 'image/jpeg');
            }
        };
        $mailer = new SymfonyRecommendationDigestMailer(
            $transport,
            new Address('reader@example.org', 'RSS Reader'),
            'https://reader.example.org',
            $images,
        );
        $article = new Article(
            42,
            10,
            'Source',
            false,
            null,
            null,
            'Article illustré',
            null,
            null,
            null,
            '2026-09-28T05:00:00Z',
            null,
            null,
            [],
            true,
        );

        $mailer->send(
            new RecommendationEmailRecipient(7, 'Alice', 'alice@example.org', 'daily', null),
            [$article],
        );

        self::assertInstanceOf(Email::class, $transport->message);
        $html = $transport->message->getHtmlBody();
        self::assertIsString($html);
        self::assertStringContainsString('cid:rss-reader-default-placeholder.png', $html);
        self::assertStringNotContainsString('cid:recommendation-42', $html);
        $attachments = $transport->message->getAttachments();
        self::assertCount(1, $attachments);
        self::assertSame('image/png', $attachments[0]->getContentType());
        self::assertSame(
            'rss-reader-default-placeholder.png',
            $attachments[0]->getName(),
        );
    }
}
