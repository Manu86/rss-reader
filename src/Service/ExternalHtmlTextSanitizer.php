<?php

declare(strict_types=1);

namespace App\Service;

use DOMDocument;

final class ExternalHtmlTextSanitizer
{
    /** @var array<string, true> */
    private const ALLOWED_TAGS = [
        'a' => true,
        'b' => true,
        'blockquote' => true,
        'br' => true,
        'code' => true,
        'em' => true,
        'h2' => true,
        'h3' => true,
        'h4' => true,
        'h1' => true,
        'i' => true,
        'img' => true,
        'li' => true,
        'ol' => true,
        'p' => true,
        'pre' => true,
        'strong' => true,
        'table' => true,
        'tbody' => true,
        'td' => true,
        'tfoot' => true,
        'th' => true,
        'thead' => true,
        'tr' => true,
        'details' => true,
        'del' => true,
        'hr' => true,
        'h5' => true,
        'h6' => true,
        'summary' => true,
        's' => true,
        'u' => true,
        'ul' => true,
    ];

    public function sanitize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (stripos($value, '<!DOCTYPE') !== false) {
            return null;
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML(
                '<html><head><meta charset="UTF-8"></head><body>' . $value . '</body></html>',
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded) {
            return null;
        }

        $body = $document->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return null;
        }
        $this->sanitizeChildren($body);

        $html = '';
        foreach ($body->childNodes as $child) {
            $serialized = $document->saveHTML($child);
            if (is_string($serialized)) {
                $html .= $serialized;
            }
        }
        $html = trim($html);
        if ($html === '') {
            return null;
        }

        return $html;
    }

    private function sanitizeChildren(\DOMNode $parent): void
    {
        for ($node = $parent->firstChild; $node !== null;) {
            $next = $node->nextSibling;
            if ($node->nodeType === XML_COMMENT_NODE) {
                $parent->removeChild($node);
            } elseif ($node->nodeType === XML_ELEMENT_NODE) {
                $tag = strtolower($node->nodeName);
                if (in_array($tag, ['iframe', 'video', 'object', 'embed'], true)) {
                    $videoUrl = $node instanceof \DOMElement ? $this->videoUrl($node, $tag) : null;
                    $document = $parent->ownerDocument;
                    if ($videoUrl === null || $document === null) {
                        $parent->removeChild($node);
                    } else {
                        $parent->replaceChild($this->videoLink($document, $videoUrl), $node);
                    }
                    $node = $next;
                    continue;
                }
                if (!isset(self::ALLOWED_TAGS[$tag])) {
                    if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'template', 'svg'], true)) {
                        $parent->removeChild($node);
                    } else {
                        $this->sanitizeChildren($node);
                        $this->unwrap($parent, $node);
                        if (
                            $tag === 'input'
                            && $next instanceof \DOMText
                        ) {
                            $text = ltrim($next->data, " \t\r\n");
                            if ($text === '') {
                                $parent->removeChild($next);
                            } else {
                                $next->data = $text;
                            }
                        }
                    }
                } else {
                    if ($this->sanitizeAttributes($node, $tag)) {
                        $this->sanitizeChildren($node);
                    } else {
                        $parent->removeChild($node);
                    }
                }
            }
            $node = $next;
        }
    }

    private function videoUrl(\DOMElement $element, string $tag): ?string
    {
        $url = match ($tag) {
            'object' => $element->getAttribute('data'),
            default => $element->getAttribute('src'),
        };
        if ($url === '' && $tag === 'video') {
            foreach ($element->getElementsByTagName('source') as $source) {
                if ($source instanceof \DOMElement && $source->hasAttribute('src')) {
                    $url = $source->getAttribute('src');
                    break;
                }
            }
        }

        return $this->isSafeLink($url) ? trim($url) : null;
    }

    private function videoLink(\DOMDocument $document, string $url): \DOMElement
    {
        $paragraph = $document->createElement('p');
        $link = $document->createElement('a');
        $link->setAttribute('href', $url);
        $link->setAttribute('target', '_blank');
        $link->setAttribute('rel', 'noopener noreferrer');
        $link->appendChild($document->createTextNode('▶ Regarder la vidéo (nouvel onglet)'));
        $paragraph->appendChild($link);

        return $paragraph;
    }

    private function sanitizeAttributes(\DOMNode $node, string $tag): bool
    {
        if (!$node instanceof \DOMElement) {
            return true;
        }
        $href = $tag === 'a' ? $node->getAttribute('href') : '';
        $src = $tag === 'img' ? $node->getAttribute('src') : '';
        $alt = $tag === 'img' ? $node->getAttribute('alt') : '';
        while ($node->attributes->length > 0) {
            $attribute = $node->attributes->item(0);
            if ($attribute === null) {
                break;
            }
            $node->removeAttributeNode($attribute);
        }
        if ($tag === 'a' && $this->isSafeLink($href)) {
            $node->setAttribute('href', $href);
            $node->setAttribute('rel', 'noopener noreferrer');
            $node->setAttribute('target', '_blank');
        }
        if ($tag === 'img') {
            if (!$this->isSafeLink($src)) {
                return false;
            }
            $node->setAttribute('src', $src);
            $node->setAttribute('alt', mb_substr($alt, 0, 500, 'UTF-8'));
            $node->setAttribute('loading', 'lazy');
            $node->setAttribute('decoding', 'async');
            $node->setAttribute('referrerpolicy', 'no-referrer');
        }

        return true;
    }

    private function unwrap(\DOMNode $parent, \DOMNode $node): void
    {
        while ($node->firstChild !== null) {
            $parent->insertBefore($node->firstChild, $node);
        }
        $parent->removeChild($node);
    }

    private function isSafeLink(string $value): bool
    {
        $value = trim($value);
        if ($value === '' || preg_match('/[\x00-\x20]/', $value) === 1) {
            return false;
        }
        $parts = parse_url($value);
        if (!is_array($parts)) {
            return false;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        return in_array($scheme, ['http', 'https'], true)
            && isset($parts['host'])
            && !isset($parts['user'])
            && !isset($parts['pass']);
    }
}
