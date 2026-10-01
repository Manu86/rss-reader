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
    /** Longueur minimale d'un bloc de texte éditorial conservé. */
    private const MINIMUM_BLOCK_CHARACTERS = 60;

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

        $jsonLdCandidate = null;
        foreach ($this->jsonLdBodies($document) as $body) {
            $content = $this->sanitize($this->jsonLdBodyMarkup($body), $documentUrl);
            if (ArticleContentPolicy::isSubstantial($content)) {
                $jsonLdCandidate = $content;
                break;
            }
        }

        $domCandidate = null;
        foreach ($this->contentElements($document) as $element) {
            $content = $this->sanitize($this->innerHtml($document, $element), $documentUrl);
            if (ArticleContentPolicy::isSubstantial($content)) {
                $domCandidate = $content;
                break;
            }
        }

        // Certains éditeurs publient un articleBody JSON-LD aplati en un seul
        // paragraphe sans titres ni listes, alors que la page structure le
        // même texte en paragraphes et intertitres : le DOM gagne alors, la
        // mise en page de l'article étant préservée. Dans les autres cas le
        // articleBody JSON-LD — la déclaration officielle de l'éditeur —
        // reste la source : le DOM, souvent noyé sous les habillages du
        // site, ne le supplante que sur cette richesse structurelle.
        $domBeatsJsonLd = $domCandidate !== null
            && $jsonLdCandidate !== null
            && $this->isFlatParagraph($jsonLdCandidate)
            && !$this->isFlatParagraph($domCandidate);
        if ($jsonLdCandidate !== null && !$domBeatsJsonLd) {
            return $jsonLdCandidate;
        }

        return $domCandidate ?? $jsonLdCandidate;
    }

    /** Le candidat est un seul paragraphe sans structure éditoriale. */
    private function isFlatParagraph(string $content): bool
    {
        $document = $this->document('<html><body>' . $content . '</body></html>');
        if ($document === null) {
            return false;
        }
        $body = $document->getElementsByTagName('body')->item(0);
        if (!$body instanceof DOMElement) {
            return false;
        }
        $elements = [];
        foreach ($body->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $elements[] = $child;
            }
        }
        if (count($elements) !== 1
            || strtolower($elements[0]->tagName) !== 'p'
            || strtolower(trim($elements[0]->getAttribute('class'))) !== '') {
            return false;
        }

        return $elements[0]->getElementsByTagName('h2')->length === 0
            && $elements[0]->getElementsByTagName('h3')->length === 0
            && $elements[0]->getElementsByTagName('ul')->length === 0
            && $elements[0]->getElementsByTagName('ol')->length === 0
            && $elements[0]->getElementsByTagName('blockquote')->length === 0;
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
        $this->removeNonEditorial($clone);
        $html = '';
        foreach ($clone->childNodes as $child) {
            $serialized = $document->saveHTML($child);
            if (is_string($serialized)) {
                $html .= $serialized;
            }
        }

        return $html;
    }

    /**
     * Les blocs trop courts pour du contenu éditorial — liens d'action,
     * boutons de partage, raccourcis d'interface — ne sont pas du papier à
     * lire : les paragraphes et les items de liste doivent porter du texte,
     * les titres et citations gardent leur rôle quel que soit leur texte.
     */
    private function removeNonEditorial(DOMElement $root): void
    {
        $remove = [];
        foreach ($root->getElementsByTagName('*') as $element) {
            if (!$element instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($element->tagName);
            if (!in_array($tag, ['p', 'li'], true)) {
                continue;
            }
            $text = preg_replace('/\s+/u', ' ', trim($element->textContent)) ?? '';
            $hasMedia = $element->getElementsByTagName('img')->length > 0
                || $element->getElementsByTagName('iframe')->length > 0;
            if (mb_strlen($text, 'UTF-8') < self::MINIMUM_BLOCK_CHARACTERS && !$hasMedia) {
                $remove[] = $element;
            }
        }
        foreach (array_reverse($remove) as $element) {
            if ($element->parentNode !== null) {
                $element->parentNode->removeChild($element);
            }
        }
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
                || preg_match('/\b(?:advert|publicit|related|recommend|comment|share|social|cookie|newsletter)\b/i', $marker) === 1
                || preg_match('/(?:sticky|carousel|shuffle|preferred-source)/i', $marker) === 1) {
                $remove[] = $element;
            }
        }
        foreach (array_reverse($remove) as $element) {
            $element->parentNode?->removeChild($element);
        }
        $this->removeEmptyBlocks($root);
    }

    /**
     * Après le retrait du bruit, les conteneurs vidés — listes d'actions
     * retournées à zéro, séparateurs de gabarit — ne portent plus rien :
     * ils deviennent du blanc imprimé s'ils ne sont pas purgés.
     */
    private function removeEmptyBlocks(DOMElement $root): void
    {
        for ($pass = 0; $pass < 3; ++$pass) {
            $empty = [];
            foreach ($root->getElementsByTagName('*') as $element) {
                if (!$element instanceof DOMElement) {
                    continue;
                }
                $tag = strtolower($element->tagName);
                if (!in_array($tag, ['p', 'li', 'ul', 'ol', 'div', 'figure', 'span', 'section'], true)) {
                    continue;
                }
                $text = trim($element->textContent);
                if ($text === ''
                    && $element->getElementsByTagName('img')->length === 0
                    && $element->getElementsByTagName('iframe')->length === 0) {
                    $empty[] = $element;
                }
            }
            if ($empty === []) {
                return;
            }
            foreach (array_reverse($empty) as $element) {
                if ($element->parentNode !== null) {
                    $element->parentNode->removeChild($element);
                }
            }
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
