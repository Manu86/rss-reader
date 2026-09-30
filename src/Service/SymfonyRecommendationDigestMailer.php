<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\Article;
use App\Model\RecommendationEmailRecipient;
use DateTimeImmutable;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

final readonly class SymfonyRecommendationDigestMailer implements RecommendationDigestMailer
{
    private const MAX_INLINE_IMAGE_MIME_BYTES = 2_000_000;
    private const DEFAULT_RSS_IMAGE_NAME = 'rss-reader-default-placeholder.png';

    private string $baseUrl;

    public function __construct(
        private MailerInterface $mailer,
        private Address $from,
        string $baseUrl,
        private ?RecommendationImageProvider $images = null,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function send(RecommendationEmailRecipient $recipient, array $articles): void
    {
        [$inlineImages, $imageNames] = $this->inlineImages($recipient, $articles);
        $message = (new Email())
            ->from($this->from)
            ->to(new Address($recipient->email, $recipient->username))
            ->subject('Vos recommandations RSS Reader')
            ->text($this->textBody($articles))
            ->html($this->htmlBody($articles, $imageNames));
        foreach ($inlineImages as $image) {
            $message->embed($image['media']->content, $image['name'], $image['media']->contentType);
        }
        $message->getHeaders()->addTextHeader('Auto-Submitted', 'auto-generated');
        $this->mailer->send($message);
    }

    /** @param list<Article> $articles */
    private function textBody(array $articles): string
    {
        $lines = [
            'Vos recommandations RSS Reader',
            '',
        ];
        foreach ($articles as $article) {
            $lines[] = $article->title . ' — ' . $article->feedName;
            $lines[] = $this->articleDate($article) . ' · ' . $this->categoryName($article);
            $lines[] = $this->articleUrl($article);
            $lines[] = '';
        }
        $lines[] = 'Gérer la fréquence : ' . $this->baseUrl . '/#/parametres';

        return implode("\n", $lines);
    }

    /**
     * @param list<Article> $articles
     * @param array<int, string> $imageNames
     */
    private function htmlBody(array $articles, array $imageNames): string
    {
        $cards = '';
        foreach ($articles as $article) {
            $details = sprintf(
                '<h2 style="margin:0 0 9px;color:#17212b;font:700 17px/23px Arial,sans-serif">'
                . '<a class="recommendation-title-link" href="%s" '
                . 'style="color:#17212b;text-decoration:none">%s</a></h2>'
                . '<table role="presentation" cellpadding="0" cellspacing="0"><tr>'
                . '<td style="padding:0 8px 0 0;color:#596673;font:12px/18px Arial,sans-serif">%s</td>'
                . '<td class="recommendation-category" style="padding:2px 7px;color:#596673;background:#f4f6f8;border:1px solid #dce3e8;'
                . 'border-radius:999px;font:12px/16px Arial,sans-serif">%s</td>'
                . '</tr></table>',
                $this->escape($this->articleUrl($article)),
                $this->escape($article->title),
                $this->escape($this->articleDate($article)),
                $this->escape($this->categoryName($article)),
            );
            $imageName = $imageNames[$article->id] ?? self::DEFAULT_RSS_IMAGE_NAME;
            $main = sprintf(
                '<table role="presentation" width="100%%" cellpadding="0" cellspacing="0"><tr>'
                . '<td width="100" style="width:100px;padding:0 12px 0 0;vertical-align:top">'
                . '<img src="cid:%s" width="88" height="88" alt="" '
                . 'style="display:block;width:88px;height:88px;border:0;border-radius:6px;object-fit:cover">'
                . '</td><td style="vertical-align:middle">%s</td></tr></table>',
                $this->escape($imageName),
                $details,
            );
            $cards .= sprintf(
                '<tr><td style="padding:0 0 12px">'
                . '<table role="presentation" width="100%%" cellpadding="0" cellspacing="0" '
                . 'class="recommendation-card" style="width:100%%;border-collapse:separate;background:#ffffff;border:1px solid #dce3e8;'
                . 'border-radius:10px"><tr><td style="padding:16px">'
                . '<table role="presentation" cellpadding="0" cellspacing="0"><tr>'
                . '<td style="padding:0 8px 0 0;vertical-align:middle">'
                . '<span style="display:inline-block;width:22px;height:22px;line-height:22px;text-align:center;'
                . 'color:#1264a3;background:#e5f1fb;border-radius:5px;font:700 12px Arial,sans-serif">%s</span>'
                . '</td><td style="color:#596673;font:12px/18px Arial,sans-serif;vertical-align:middle">%s</td>'
                . '</tr></table>'
                . '<div style="height:8px;line-height:8px">&nbsp;</div>%s'
                . '</td></tr></table></td></tr>',
                $this->escape($this->sourceInitial($article->feedName)),
                $this->escape($article->feedName),
                $main,
            );
        }

        return '<!doctype html><html lang="fr"><head><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="color-scheme" content="light dark">'
            . '<meta name="supported-color-schemes" content="light dark">'
            . '<style type="text/css">'
            . ':root{color-scheme:light dark;}'
            . '.recommendation-title-link:hover{color:#1264a3!important;text-decoration:underline!important;}'
            . '@media(prefers-color-scheme:dark){'
            . 'body{background:#151b21!important;color:#e4e9ed!important;}'
            . '.recommendation-card{background:#202830!important;border-color:#303a43!important;}'
            . '.recommendation-category{background:#252e36!important;border-color:#36414b!important;color:#c1cbd3!important;}'
            . '}'
            . '[data-ogsc] .recommendation-card{background:#202830!important;border-color:#303a43!important;}'
            . '[data-ogsc] .recommendation-category{background:#252e36!important;border-color:#36414b!important;color:#c1cbd3!important;}'
            . '</style>'
            . '</head>'
            . '<body style="margin:0;padding:0;background:#f4f6f8;color:#17212b">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" '
            . 'style="width:100%;border-collapse:collapse;background:#f4f6f8"><tr><td align="center" style="padding:24px 12px">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" '
            . 'style="width:100%;max-width:600px;border-collapse:collapse">'
            . '<tr><td style="padding:0 0 18px">'
            . '<p style="margin:0 0 5px;color:#1264a3;font:700 12px/18px Arial,sans-serif;letter-spacing:1.2px;'
            . 'text-transform:uppercase">RSS Reader</p>'
            . '<h1 style="margin:0;color:#17212b;font:700 25px/32px Arial,sans-serif">Recommandé pour vous</h1>'
            . '</td></tr>'
            . $cards
            . '<tr><td style="padding:10px 0 0;color:#596673;font:13px/20px Arial,sans-serif;text-align:center">'
            . '<a href="' . $this->escape($this->baseUrl . '/#/recommandations')
            . '" style="display:inline-block;padding:10px 15px;color:#ffffff;background:#1264a3;border-radius:7px;'
            . 'text-decoration:none;font-weight:700">Ouvrir mes recommandations</a>'
            . '<p style="margin:18px 0 0"><a href="' . $this->escape($this->baseUrl . '/#/parametres')
            . '" style="color:#1264a3">Gérer la fréquence des emails</a></p>'
            . '</td></tr></table></td></tr></table></body></html>';
    }

    /**
     * @param list<Article> $articles
     * @return array{list<array{name: string, media: \App\Model\StoredMedia}>, array<int, string>}
     */
    private function inlineImages(RecommendationEmailRecipient $recipient, array $articles): array
    {
        if ($this->images === null) {
            return [[], []];
        }
        $embedded = [];
        $names = [];
        $totalBytes = 0;
        foreach ($articles as $article) {
            $media = null;
            try {
                $media = $this->images->forArticle($recipient->userId, $article);
            } catch (Throwable) {
                // Missing or unreadable local media falls back to the RSS pictogram.
            }
            if ($media === null || !str_starts_with($media->contentType, 'image/') || $media->content === '') {
                $names[$article->id] = self::DEFAULT_RSS_IMAGE_NAME;
                continue;
            }
            $bytes = strlen($media->content);
            if ((int) ceil(($totalBytes + $bytes) * 4 / 3) > self::MAX_INLINE_IMAGE_MIME_BYTES) {
                $names[$article->id] = self::DEFAULT_RSS_IMAGE_NAME;
                continue;
            }
            $name = 'recommendation-' . $article->id . '.' . $this->imageExtension($media->contentType);
            $embedded[] = ['name' => $name, 'media' => $media];
            $names[$article->id] = $name;
            $totalBytes += $bytes;
        }

        if (in_array(self::DEFAULT_RSS_IMAGE_NAME, $names, true)) {
            $placeholder = $this->defaultRssImage();
            if ($placeholder !== null) {
                $embedded[] = [
                    'name' => self::DEFAULT_RSS_IMAGE_NAME,
                    'media' => new \App\Model\StoredMedia($placeholder, 'image/png'),
                ];
            }
        }

        return [$embedded, $names];
    }

    private function defaultRssImage(): ?string
    {
        $image = imagecreatetruecolor(88, 88);
        if ($image === false) {
            return null;
        }
        try {
            $background = imagecolorallocate($image, 237, 241, 244);
            $foreground = imagecolorallocate($image, 164, 176, 185);
            if ($background === false || $foreground === false) {
                return null;
            }
            imagefill($image, 0, 0, $background);
            imagesetthickness($image, 5);
            imagearc($image, 18, 70, 66, 66, 270, 360, $foreground);
            imagearc($image, 18, 70, 116, 116, 270, 360, $foreground);
            imagefilledellipse($image, 18, 70, 13, 13, $foreground);
            ob_start();
            $encoded = imagepng($image);
            $content = ob_get_clean();

            return $encoded && is_string($content) && $content !== '' ? $content : null;
        } finally {
            imagedestroy($image);
        }
    }

    private function imageExtension(string $contentType): string
    {
        return match ($contentType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => 'img',
        };
    }

    private function sourceInitial(string $feedName): string
    {
        $name = trim($feedName);

        return $name === '' ? '?' : mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8'), 'UTF-8');
    }

    private function categoryName(Article $article): string
    {
        $category = trim($article->categoryName ?? '');

        return $category === '' ? 'Sans catégorie' : $category;
    }

    private function articleDate(Article $article): string
    {
        $value = $article->publishedAt ?? $article->discoveredAt;
        try {
            $date = new DateTimeImmutable($value);
        } catch (Throwable) {
            return 'Date inconnue';
        }
        $months = [
            1 => 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin',
            'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.',
        ];

        return $date->format('d') . ' ' . $months[(int) $date->format('n')] . ' ' . $date->format('Y');
    }

    private function articleUrl(Article $article): string
    {
        return $this->baseUrl . '/#/articles/' . $article->id
            . '?from=' . rawurlencode('/#/recommandations');
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
