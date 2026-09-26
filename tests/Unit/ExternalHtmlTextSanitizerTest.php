<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Service\ExternalHtmlTextSanitizer;
use PHPUnit\Framework\TestCase;

final class ExternalHtmlTextSanitizerTest extends TestCase
{
    public function testKeepsSafeReadingMarkup(): void
    {
        $html = (new ExternalHtmlTextSanitizer())->sanitize(
            '<h1>Actualité</h1><h2>Sous-titre</h2><p>Un texte <strong>important</strong>.</p><ul><li>Premier</li><li>Second</li></ul><ol><li>Un</li></ol><details><summary>Plus</summary><table><tr><th>A</th><td>B</td></tr></table></details>',
        );

        self::assertNotNull($html);
        self::assertStringContainsString('<h1>Actualité</h1>', $html);
        self::assertStringContainsString('<h2>Sous-titre</h2>', $html);
        self::assertStringContainsString('<strong>important</strong>', $html);
        self::assertStringContainsString('<ul><li>Premier</li><li>Second</li></ul>', $html);
        self::assertStringContainsString('<ol><li>Un</li></ol>', $html);
        self::assertStringContainsString('<details><summary>Plus</summary>', $html);
        self::assertStringContainsString('<table><tr><th>A</th><td>B</td></tr></table>', $html);
    }

    public function testRemovesActiveContentAndUnsafeLinks(): void
    {
        $html = (new ExternalHtmlTextSanitizer())->sanitize(
            '<script>alert(1)</script><p onclick="alert(2)">Texte</p><a href="javascript:alert(3)">Danger</a><a href="https://example.test">Sûr</a>',
        );

        self::assertNotNull($html);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('onclick', $html);
        self::assertStringNotContainsString('javascript:', $html);
        self::assertStringContainsString('href="https://example.test"', $html);
        self::assertStringContainsString('target="_blank"', $html);
    }

    public function testReplacesSafeVideoEmbedsWithVisibleLinks(): void
    {
        $html = (new ExternalHtmlTextSanitizer())->sanitize(
            '<iframe src="https://video.example.test/watch/123"></iframe>'
            . '<video><source src="https://cdn.example.test/movie.mp4"></video>'
            . '<iframe src="javascript:alert(1)"></iframe>',
        );

        self::assertNotNull($html);
        self::assertSame(2, substr_count($html, 'Regarder la vidéo (nouvel onglet)'));
        self::assertStringContainsString('href="https://video.example.test/watch/123"', $html);
        self::assertStringContainsString('href="https://cdn.example.test/movie.mp4"', $html);
        self::assertStringContainsString('target="_blank"', $html);
        self::assertStringContainsString('rel="noopener noreferrer"', $html);
        self::assertStringNotContainsString('<iframe', $html);
        self::assertStringNotContainsString('<video', $html);
        self::assertStringNotContainsString('javascript:', $html);
    }

    public function testKeepsOnlySafeAbsoluteImagesWithPresentationAttributes(): void
    {
        $html = (new ExternalHtmlTextSanitizer())->sanitize(
            '<img src="https://cdn.example.test/image.jpg" alt="Illustration" onerror="alert(1)">'
            . '<img src="/relative.jpg"><img src="data:image/png;base64,abc">'
            . '<img src="https://user:secret@example.test/private.jpg">',
        );

        self::assertSame(
            '<img src="https://cdn.example.test/image.jpg" alt="Illustration" loading="lazy" decoding="async" referrerpolicy="no-referrer">',
            $html,
        );
    }

    public function testSanitizesAllowedChildrenInsideAnUnwrappedElement(): void
    {
        $html = (new ExternalHtmlTextSanitizer())->sanitize(
            '<figure class="remote"><img src="https://cdn.example.test/photo.jpg" alt="Photo" '
            . 'onerror="alert(1)"><script>alert(2)</script><figcaption>Légende</figcaption></figure>',
        );

        self::assertSame(
            '<img src="https://cdn.example.test/photo.jpg" alt="Photo" loading="lazy" decoding="async" '
            . 'referrerpolicy="no-referrer">Légende',
            $html,
        );
    }
}
