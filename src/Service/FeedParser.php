<?php

declare(strict_types=1);

namespace App\Service;

use App\Clock\Clock;
use App\Exception\RemoteHttpException;
use App\Exception\ValidationException;
use App\Http\UrlResolver;
use App\Model\ParsedArticle;
use App\Model\ParsedFeed;
use App\Validation\UrlNormalizer;
use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMElement;

final readonly class FeedParser
{
    private MarkdownContentConverter $markdownConverter;

    public function __construct(
        private UrlResolver $urlResolver,
        private UrlNormalizer $urlNormalizer,
        private ExternalHtmlTextSanitizer $sanitizer,
        private Clock $clock,
        ?MarkdownContentConverter $markdownConverter = null,
    ) {
        $this->markdownConverter = $markdownConverter ?? new MarkdownContentConverter();
    }

    public function parse(string $xml, string $documentUrl): ?ParsedFeed
    {
        if (stripos($xml, '<!DOCTYPE') !== false) {
            return null;
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadXML(
                $xml,
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded || !$document->documentElement instanceof DOMElement) {
            return null;
        }

        $root = $document->documentElement;
        if ($root->localName === 'rss' && str_starts_with(trim($root->getAttribute('version')), '2')) {
            return $this->parseRss($root, $documentUrl);
        }
        if ($root->localName === 'feed' && $root->namespaceURI === 'http://www.w3.org/2005/Atom') {
            return $this->parseAtom($root, $documentUrl);
        }

        return null;
    }

    private function parseRss(DOMElement $root, string $documentUrl): ?ParsedFeed
    {
        $channel = $this->firstChild($root, 'channel');
        if ($channel === null) {
            return null;
        }
        $title = $this->plainText($this->childText($channel, 'title')) ?? '';
        if ($title === '') {
            return null;
        }

        $articles = [];
        foreach ($this->children($channel, 'item') as $item) {
            $article = $this->rssArticle($item, $documentUrl);
            if ($article !== null) {
                $articles[] = $article;
            }
        }

        return new ParsedFeed(
            $title,
            $this->normalizeUrl($this->childText($channel, 'link'), $documentUrl),
            $articles,
            $this->rssFavicon($channel, $documentUrl),
        );
    }

    private function rssArticle(DOMElement $item, string $documentUrl): ?ParsedArticle
    {
        $guid = $this->boundedIdentifier($this->childText($item, 'guid'));
        $url = $this->normalizeUrl($this->childText($item, 'link'), $documentUrl);
        $author = $this->plainText(
            $this->childText($item, 'author') ?? $this->childText($item, 'creator'),
        );
        $publishedAt = $this->date($this->childText($item, 'pubDate'))
            ?? $this->date($this->childText($item, 'date'));
        $contentBaseUrl = $url ?? $documentUrl;
        $summary = $this->sanitizeArticleMarkup($this->childMarkup($item, 'description'), $contentBaseUrl);
        $content = $this->sanitizeArticleMarkup($this->childMarkup($item, 'encoded'), $contentBaseUrl);
        $title = $this->plainText($this->childText($item, 'title')) ?? 'Article sans titre';
        $imageUrl = $this->rssImage($item, $contentBaseUrl);
        $tags = $this->rssTags($item);

        return $this->article($guid, $title, $url, $author, $publishedAt, $summary, $content, $imageUrl, $tags);
    }

    private function parseAtom(DOMElement $root, string $documentUrl): ?ParsedFeed
    {
        $title = $this->plainText($this->childText($root, 'title')) ?? '';
        if ($title === '') {
            return null;
        }

        $articles = [];
        foreach ($this->children($root, 'entry') as $entry) {
            $article = $this->atomArticle($entry, $documentUrl);
            if ($article !== null) {
                $articles[] = $article;
            }
        }

        return new ParsedFeed(
            $title,
            $this->atomLink($root, $documentUrl),
            $articles,
            $this->normalizeUrl(
                $this->childText($root, 'icon') ?? $this->childText($root, 'logo'),
                $documentUrl,
            ),
        );
    }

    private function atomArticle(DOMElement $entry, string $documentUrl): ?ParsedArticle
    {
        $guid = $this->boundedIdentifier($this->childText($entry, 'id'));
        $authorElement = $this->firstChild($entry, 'author');
        $author = $authorElement === null ? null : $this->plainText($this->childText($authorElement, 'name'));
        $publishedAt = $this->date(
            $this->childText($entry, 'published') ?? $this->childText($entry, 'updated'),
        );
        $title = $this->plainText($this->childText($entry, 'title')) ?? 'Article sans titre';
        $url = $this->atomLink($entry, $documentUrl);
        $contentBaseUrl = $url ?? $documentUrl;

        return $this->article(
            $guid,
            $title,
            $url,
            $author,
            $publishedAt,
            $this->sanitizeArticleMarkup($this->atomContent($entry, 'summary'), $contentBaseUrl),
            $this->sanitizeArticleMarkup($this->atomContent($entry, 'content'), $contentBaseUrl),
            $this->atomImage($entry, $contentBaseUrl),
            $this->atomTags($entry),
        );
    }

    /** @return list<string> */
    private function rssTags(DOMElement $item): array
    {
        $tags = [];
        foreach ($this->children($item, 'category') as $category) {
            $name = $this->tagTerm($category->textContent);
            if ($name !== null) {
                $tags[] = $name;
            }
        }

        return $this->normalizeTags($tags);
    }

    /** @return list<string> */
    private function atomTags(DOMElement $entry): array
    {
        $tags = [];
        foreach ($this->children($entry, 'category') as $category) {
            $term = $category->getAttribute('term');
            $name = $this->tagTerm(
                trim($term) !== '' ? $term : $category->getAttribute('label'),
            );
            if ($name !== null) {
                $tags[] = $name;
            }
        }

        return $this->normalizeTags($tags);
    }

    private function tagTerm(string $raw): ?string
    {
        if ($raw === '') {
            return null;
        }
        $length = mb_strlen($raw, 'UTF-8');
        if ($length > 100) {
            return null;
        }

        return $this->plainText($raw);
    }

    /**
     * @param list<string> $tags
     * @return list<string>
     */
    private function normalizeTags(array $tags): array
    {
        $normalized = [];
        foreach ($tags as $tag) {
            $value = is_string($tag) ? trim($tag) : '';
            if ($value === '' || in_array($value, $normalized, true)) {
                continue;
            }
            $normalized[] = $value;
            if (count($normalized) >= 10) {
                break;
            }
        }

        return $normalized;
    }

    /**
     * @param list<string> $tags
     */
    private function article(
        ?string $guid,
        string $title,
        ?string $url,
        ?string $author,
        ?string $publishedAt,
        ?string $summary,
        ?string $content,
        ?string $imageUrl,
        array $tags = [],
    ): ?ParsedArticle {
        // Les tags sont normalisés ici en liste dédupliquée.
        $normalizedTags = $this->normalizeTags($tags);
        if ($guid !== null) {
            $identity = 'guid\0' . $guid;
        } elseif ($url !== null) {
            $identity = 'url\0' . $url;
        } elseif ($publishedAt !== null || $author !== null || $summary !== null || $content !== null) {
            $identity = 'fallback\0' . implode('\0', [
                $title,
                $publishedAt ?? '',
                $author ?? '',
                hash('sha256', ($summary ?? '') . "\0" . ($content ?? '')),
            ]);
        } else {
            return null;
        }

        return new ParsedArticle(
            $guid,
            $guid === null ? null : hash('sha256', $guid),
            $title,
            $url,
            $author,
            $publishedAt,
            $summary,
            $content,
            hash('sha256', $identity),
            $imageUrl,
            $normalizedTags,
        );
    }

    private function rssFavicon(DOMElement $channel, string $documentUrl): ?string
    {
        $image = $this->firstChild($channel, 'image');

        return $image === null ? null : $this->normalizeUrl($this->childText($image, 'url'), $documentUrl);
    }

    private function rssImage(DOMElement $item, string $documentUrl): ?string
    {
        $candidate = $this->mediaRssImage($item, $documentUrl);
        if ($candidate !== null) {
            return $candidate;
        }
        $candidate = $this->itunesImage($item, $documentUrl);
        if ($candidate !== null) {
            return $candidate;
        }
        foreach ($this->children($item, 'enclosure') as $enclosure) {
            if (str_starts_with(strtolower(trim($enclosure->getAttribute('type'))), 'image/')) {
                $candidate = $this->normalizeUrl($enclosure->getAttribute('url'), $documentUrl);
                if ($candidate !== null) {
                    return $candidate;
                }
            }
        }

        return $this->htmlImage(
            $this->childText($item, 'encoded') ?? $this->childText($item, 'description'),
            $documentUrl,
        );
    }

    /**
     * Les flux de podcasts publient la vignette de l'épisode dans
     * itunes:image (attribut href) : c'est souvent leur seul visuel.
     */
    private function itunesImage(DOMElement $parent, string $documentUrl): ?string
    {
        foreach ($this->children($parent, 'image') as $image) {
            if (!$image instanceof DOMElement) {
                continue;
            }
            if (($image->namespaceURI ?? '') !== 'http://www.itunes.com/dtds/podcast-1.0.dtd') {
                // La vignette de canal RSS <image><url> reste une favicon.
                continue;
            }
            $candidate = $this->normalizeUrl($image->getAttribute('href'), $documentUrl);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    private function atomImage(DOMElement $entry, string $documentUrl): ?string
    {
        $candidate = $this->mediaRssImage($entry, $documentUrl);
        if ($candidate !== null) {
            return $candidate;
        }
        $candidate = $this->itunesImage($entry, $documentUrl);
        if ($candidate !== null) {
            return $candidate;
        }
        foreach ($this->children($entry, 'link') as $link) {
            if (strtolower(trim($link->getAttribute('rel'))) === 'enclosure'
                && str_starts_with(strtolower(trim($link->getAttribute('type'))), 'image/')) {
                $candidate = $this->normalizeUrl($link->getAttribute('href'), $documentUrl);
                if ($candidate !== null) {
                    return $candidate;
                }
            }
        }

        return $this->htmlImage(
            $this->atomContent($entry, 'content') ?? $this->atomContent($entry, 'summary'),
            $documentUrl,
        );
    }

    private function mediaRssImage(DOMElement $parent, string $documentUrl): ?string
    {
        foreach (['content', 'thumbnail'] as $name) {
            $elements = $parent->getElementsByTagNameNS('http://search.yahoo.com/mrss/', $name);
            foreach ($elements as $element) {
                if (!$element instanceof DOMElement) {
                    continue;
                }
                if (!$this->mediaElementIsImage($element, $name)) {
                    continue;
                }
                $candidate = $this->normalizeUrl($element->getAttribute('url'), $documentUrl);
                if ($candidate !== null) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * Un media:content sans attribut medium doit rester un candidat image
     * uniquement lorsque son type déclare image/*. Les flux vidéo, YouTube en
     * tête, publient un lecteur (application/x-shockwave-flash) sans medium :
     * retenir ce contenu masquerait la vignette media:thumbnail qui suit.
     */
    private function mediaElementIsImage(DOMElement $element, string $name): bool
    {
        if ($name !== 'content') {
            return true;
        }
        $medium = strtolower(trim($element->getAttribute('medium')));
        if ($medium === 'image') {
            return true;
        }
        if ($medium !== '') {
            return false;
        }
        $type = strtolower(trim($element->getAttribute('type')));

        return $type === '' || str_starts_with($type, 'image/');
    }

    private function htmlImage(?string $html, string $documentUrl): ?string
    {
        if ($html === null || trim($html) === '' || stripos($html, '<!DOCTYPE') !== false) {
            return null;
        }
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML(
                '<html><body>' . $html . '</body></html>',
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded) {
            return null;
        }
        foreach ($document->getElementsByTagName('img') as $image) {
            $candidate = $this->normalizeUrl($image->getAttribute('src'), $documentUrl);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    private function sanitizeArticleMarkup(?string $html, string $baseUrl): ?string
    {
        if ($html === null || trim($html) === '' || stripos($html, '<!DOCTYPE') !== false) {
            return $this->sanitizer->sanitize($html);
        }
        if (preg_match('/<\/?[a-z][a-z0-9-]*(?:\s[^<>]*)?\s*\/?>/i', $html) !== 1) {
            $html = $this->markdownConverter->convert($html);
        }
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML(
                '<html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>',
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded) {
            return $this->sanitizer->sanitize($html);
        }
        $body = $document->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return $this->sanitizer->sanitize($html);
        }
        $images = [];
        foreach ($body->getElementsByTagName('img') as $image) {
            $images[] = $image;
        }
        foreach ($images as $image) {
            $source = $this->normalizeUrl($image->getAttribute('src'), $baseUrl);
            if ($source === null) {
                $image->parentNode?->removeChild($image);
                continue;
            }
            $image->setAttribute('src', $source);
        }
        $videoElements = [];
        foreach (['iframe', 'video', 'object', 'embed'] as $tag) {
            foreach ($body->getElementsByTagName($tag) as $element) {
                if ($element instanceof DOMElement) {
                    $videoElements[] = [$element, $tag];
                }
            }
        }
        foreach ($videoElements as [$element, $tag]) {
            $attribute = $tag === 'object' ? 'data' : 'src';
            $mediaElement = $element;
            if (!$mediaElement->hasAttribute($attribute) && $tag === 'video') {
                $sources = $element->getElementsByTagName('source');
                $mediaElement = $sources->item(0);
                $attribute = 'src';
            }
            if (!$mediaElement instanceof DOMElement || !$mediaElement->hasAttribute($attribute)) {
                continue;
            }
            $source = $this->normalizeUrl($mediaElement->getAttribute($attribute), $baseUrl);
            if ($source !== null) {
                $mediaElement->setAttribute($attribute, $source);
            }
        }
        $links = [];
        foreach ($body->getElementsByTagName('a') as $link) {
            $links[] = $link;
        }
        foreach ($links as $link) {
            $target = $this->normalizeUrl($link->getAttribute('href'), $baseUrl);
            if ($target === null) {
                $link->removeAttribute('href');
                continue;
            }
            $link->setAttribute('href', $target);
        }
        $normalized = '';
        foreach ($body->childNodes as $child) {
            $serialized = $document->saveHTML($child);
            if (is_string($serialized)) {
                $normalized .= $serialized;
            }
        }

        return $this->sanitizer->sanitize($normalized);
    }

    private function atomLink(DOMElement $parent, string $documentUrl): ?string
    {
        foreach ($this->children($parent, 'link') as $link) {
            $rel = strtolower(trim($link->getAttribute('rel')));
            if ($rel === '' || $rel === 'alternate') {
                $url = $this->normalizeUrl($link->getAttribute('href'), $documentUrl);
                if ($url !== null) {
                    return $url;
                }
            }
        }

        return null;
    }

    private function normalizeUrl(?string $value, string $documentUrl): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        try {
            return $this->urlNormalizer->normalizeHttpUrl(
                $this->urlResolver->resolve($documentUrl, trim($value)),
                'url',
            );
        } catch (RemoteHttpException|ValidationException) {
            return null;
        }
    }

    private function date(?string $value): ?string
    {
        if ($value === null || strlen($value) > 100 || preg_match('/\b[0-9]{4}\b/', $value) !== 1) {
            return null;
        }
        try {
            $date = new DateTimeImmutable($value);
        } catch (\Exception) {
            $date = $this->localizedDate($value);
            if ($date === null) {
                return null;
            }
        }
        $minimum = new DateTimeImmutable('1970-01-01T00:00:00Z');
        $maximum = $this->clock->now()->modify('+7 days');
        if ($date < $minimum || $date > $maximum) {
            return null;
        }

        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * Certains gabarits francophones publient une date rédigée en toutes
     * lettres (« Vendredi 25 septembre 2026 - 12:00 ») que DateTimeImmutable
     * ne décode pas. Les mots du jour et du mois sont traduits, puis
     * l'intitulé du jour, s'il existe, est retiré : il n'apporte rien à
     * l'analyse et ses variantes (1er, prem.) brouilleraient le motif.
     */
    private function localizedDate(string $value): ?DateTimeImmutable
    {
        $months = [
            'janvier' => 'January', 'février|fevrier' => 'February', 'mars' => 'March',
            'avril' => 'April', 'mai' => 'May', 'juin' => 'June', 'juillet' => 'July',
            'août|aout' => 'August', 'septembre' => 'September', 'octobre' => 'October',
            'novembre' => 'November', 'décembre|decembre' => 'December',
        ];
        $normalized = ' ' . mb_strtolower(trim($value)) . ' ';
        $normalized = str_replace('é', 'e', $normalized);
        $english = '';
        foreach (preg_split('/\s+/', trim($normalized)) ?: [] as $word) {
            foreach ($months as $french => $englishMonth) {
                foreach (explode('|', $french) as $spelling) {
                    if ($word === str_replace('é', 'e', $spelling)) {
                        $word = strtolower($englishMonth);
                        break 2;
                    }
                }
            }
            $english .= ' ' . $word;
        }
        $english = preg_replace(
            '/\b(?:lundi|mardi|mercredi|jeudi|vendredi|samedi|dimanche|1er|prem(?:ier|\.)?)\b/',
            '',
            $english,
        );
        if (!is_string($english)) {
            return null;
        }
        $english = str_replace(' à ', ' ', $english);
        $english = preg_replace('/[,\-:]\s*(\d{1,2}:\d{2})\b/', ' $1', $english);
        if (!is_string($english)) {
            return null;
        }
        $trimmed = trim(preg_replace('/\s+/', ' ', $english) ?? '');
        if ($trimmed === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($trimmed);
        } catch (\Exception) {
            return null;
        }
    }

    private function boundedIdentifier(?string $value): ?string
    {
        $value = $value === null ? '' : trim($value);

        return $value !== '' && strlen($value) <= 2048 ? $value : null;
    }

    private function plainText(?string $value): ?string
    {
        $sanitized = $this->sanitizer->sanitize($value);
        if ($sanitized === null) {
            return null;
        }

        return html_entity_decode(strip_tags($sanitized), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function childText(DOMElement $parent, string $name): ?string
    {
        $child = $this->firstChild($parent, $name);

        return $child === null ? null : trim($child->textContent);
    }

    private function childMarkup(DOMElement $parent, string $name): ?string
    {
        $child = $this->firstChild($parent, $name);
        if ($child === null) {
            return null;
        }
        $markup = '';
        foreach ($child->childNodes as $node) {
            $serialized = $node instanceof \DOMCharacterData
                ? $node->data
                : $child->ownerDocument?->saveXML($node);
            if (is_string($serialized)) {
                $markup .= $serialized;
            }
        }

        return $markup !== '' ? $markup : $child->textContent;
    }

    private function atomContent(DOMElement $parent, string $name): ?string
    {
        $child = $this->firstChild($parent, $name);
        if ($child === null) {
            return null;
        }

        return strtolower(trim($child->getAttribute('type'))) === 'xhtml'
            ? $this->childMarkup($parent, $name)
            : trim($child->textContent);
    }

    private function firstChild(DOMElement $parent, string $name): ?DOMElement
    {
        foreach ($parent->childNodes as $node) {
            if ($node instanceof DOMElement && $node->localName === $name) {
                return $node;
            }
        }

        return null;
    }

    /** @return list<DOMElement> */
    private function children(DOMElement $parent, string $name): array
    {
        $children = [];
        foreach ($parent->childNodes as $node) {
            if ($node instanceof DOMElement && $node->localName === $name) {
                $children[] = $node;
            }
        }

        return $children;
    }
}
