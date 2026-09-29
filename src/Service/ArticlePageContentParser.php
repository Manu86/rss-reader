<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\RemoteHttpException;
use App\Exception\ValidationException;
use App\Http\UrlResolver;
use App\Validation\UrlNormalizer;
use DOMDocument;
use DOMElement;
use DOMXPath;

final readonly class ArticlePageContentParser
{
    private const UPPERCASE = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    private const LOWERCASE = 'abcdefghijklmnopqrstuvwxyz';

    public function __construct(
        private UrlResolver $urlResolver,
        private UrlNormalizer $urlNormalizer,
        private ExternalHtmlTextSanitizer $sanitizer,
    ) {}

    public function parse(string $html, string $documentUrl): ?string
    {
        $document = $this->document($html);
        if ($document === null) {
            return null;
        }

        foreach ($this->jsonLdBodies($document) as $body) {
            $content = $this->sanitize($this->jsonLdBodyMarkup($body), $documentUrl);
            if (ArticleContentPolicy::isSubstantial($content)) {
                return $content;
            }
        }

        foreach ($this->contentElements($document) as $element) {
            $content = $this->sanitize($this->innerHtml($document, $element), $documentUrl);
            if (ArticleContentPolicy::isSubstantial($content)) {
                return $content;
            }
        }

        return null;
    }

    private function document(string $html): ?DOMDocument
    {
        if (trim($html) === '') {
            return null;
        }
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML(
                '<?xml encoding="UTF-8">' . $html,
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $loaded ? $document : null;
    }

    /** @return list<string> */
    private function jsonLdBodies(DOMDocument $document): array
    {
        $bodies = [];
        foreach ($document->getElementsByTagName('script') as $script) {
            if (!$script instanceof DOMElement
                || strtolower(trim($script->getAttribute('type'))) !== 'application/ld+json') {
                continue;
            }
            try {
                $decoded = json_decode($script->textContent, true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }
            $this->collectJsonLdBodies($decoded, $bodies);
        }

        return $bodies;
    }

    /** @param list<string> $bodies */
    private function collectJsonLdBodies(mixed $value, array &$bodies): void
    {
        if (!is_array($value)) {
            return;
        }
        $type = $value['@type'] ?? null;
        $types = is_array($type) ? $type : [$type];
        $isArticle = array_filter($types, static fn(mixed $item): bool => is_string($item)
            && in_array(strtolower($item), ['article', 'newsarticle', 'blogposting'], true)) !== [];
        if ($isArticle && isset($value['articleBody']) && is_string($value['articleBody'])) {
            $body = trim($value['articleBody']);
            if ($body !== '') {
                $bodies[] = $body;
            }
        }
        foreach ($value as $child) {
            if (is_array($child)) {
                $this->collectJsonLdBodies($child, $bodies);
            }
        }
    }

    /** @return list<DOMElement> */
    private function contentElements(DOMDocument $document): array
    {
        $xpath = new DOMXPath($document);
        $queries = [
            '//*[@itemprop="articleBody"]',
            '//*[contains(translate(@data-testid, "' . self::UPPERCASE . '", "' . self::LOWERCASE . '"), "article")'
                . ' and (contains(translate(@data-testid, "' . self::UPPERCASE . '", "' . self::LOWERCASE . '"), "content")'
                . ' or contains(translate(@data-testid, "' . self::UPPERCASE . '", "' . self::LOWERCASE . '"), "contenu"))]',
            '//*[contains(concat(" ", translate(normalize-space(@class), "' . self::UPPERCASE . '", "' . self::LOWERCASE . '"), " "), " article-body ")'
                . ' or contains(concat(" ", translate(normalize-space(@class), "' . self::UPPERCASE . '", "' . self::LOWERCASE . '"), " "), " article-content ")'
                . ' or contains(concat(" ", translate(normalize-space(@class), "' . self::UPPERCASE . '", "' . self::LOWERCASE . '"), " "), " entry-content ")'
                . ' or contains(concat(" ", translate(normalize-space(@class), "' . self::UPPERCASE . '", "' . self::LOWERCASE . '"), " "), " post-content ")]',
            '//article',
        ];
        $elements = [];
        foreach ($queries as $query) {
            $nodes = $xpath->query($query);
            if ($nodes === false) {
                continue;
            }
            foreach ($nodes as $node) {
                if ($node instanceof DOMElement && !in_array($node, $elements, true)) {
                    $elements[] = $node;
                }
            }
        }

        return $elements;
    }

    private function jsonLdBodyMarkup(string $body): string
    {
        $decoded = $body;
        for ($pass = 0; $pass < 3; ++$pass) {
            $next = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($next === $decoded) {
                break;
            }
            $decoded = $next;
        }

        if (preg_match('/<\/?(?:p|h[1-6]|ul|ol|li|blockquote|pre|code|strong|em|a|img|table|br)\b/i', $decoded) === 1) {
            return $decoded;
        }

        return $this->plainTextMarkup($decoded);
    }

    private function plainTextMarkup(string $body): string
    {
        $paragraphs = preg_split('/\R{2,}/u', trim($body)) ?: [$body];

        return implode('', array_map(
            static fn(string $paragraph): string => '<p>'
                . htmlspecialchars(trim($paragraph), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8') . '</p>',
            array_filter($paragraphs, static fn(string $paragraph): bool => trim($paragraph) !== ''),
        ));
    }

    private function innerHtml(DOMDocument $document, DOMElement $element): string
    {
        $clone = $element->cloneNode(true);
        if (!$clone instanceof DOMElement) {
            return '';
        }
        $this->removeNoise($clone);
        $html = '';
        foreach ($clone->childNodes as $child) {
            $serialized = $document->saveHTML($child);
            if (is_string($serialized)) {
                $html .= $serialized;
            }
        }

        return $html;
    }

    private function removeNoise(DOMElement $root): void
    {
        $remove = [];
        foreach ($root->getElementsByTagName('*') as $element) {
            if (!$element instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($element->tagName);
            $marker = strtolower($element->getAttribute('class') . ' ' . $element->getAttribute('id'));
            if (in_array($tag, ['script', 'style', 'noscript', 'header', 'nav', 'footer', 'aside', 'form', 'button'], true)
                || preg_match('/\b(?:advert|publicit|related|recommend|comment|share|social|cookie|newsletter)\b/i', $marker) === 1) {
                $remove[] = $element;
            }
        }
        foreach (array_reverse($remove) as $element) {
            $element->parentNode?->removeChild($element);
        }
    }

    private function sanitize(string $html, string $baseUrl): ?string
    {
        $document = $this->document('<html><body>' . $html . '</body></html>');
        if ($document === null) {
            return null;
        }
        $body = $document->getElementsByTagName('body')->item(0);
        if (!$body instanceof DOMElement) {
            return null;
        }
        foreach (['a' => 'href', 'img' => 'src'] as $tag => $attribute) {
            $elements = [];
            foreach ($body->getElementsByTagName($tag) as $element) {
                if ($element instanceof DOMElement) {
                    $elements[] = $element;
                }
            }
            foreach ($elements as $element) {
                $url = $this->normalizeUrl($element->getAttribute($attribute), $baseUrl);
                if ($url === null) {
                    if ($tag === 'img') {
                        $element->parentNode?->removeChild($element);
                    } else {
                        $element->removeAttribute($attribute);
                    }
                    continue;
                }
                $element->setAttribute($attribute, $url);
            }
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

    private function normalizeUrl(string $value, string $baseUrl): ?string
    {
        if (trim($value) === '') {
            return null;
        }
        try {
            return $this->urlNormalizer->normalizeHttpUrl(
                $this->urlResolver->resolve($baseUrl, trim($value)),
                'url',
            );
        } catch (RemoteHttpException|ValidationException) {
            return null;
        }
    }
}
