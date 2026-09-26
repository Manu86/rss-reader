<?php

declare(strict_types=1);

namespace App\Service;

use DOMDocument;
use DOMElement;

final class FeedDocumentDetector
{
    /** @return array{title: string, type: 'rss'|'atom'}|null */
    public function detect(string $body): ?array
    {
        if (stripos($body, '<!DOCTYPE') !== false) {
            return null;
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadXML(
                $body,
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
        if ($root->localName === 'rss'
            && str_starts_with(trim($root->getAttribute('version')), '2')) {
            foreach ($root->childNodes as $node) {
                if ($node instanceof DOMElement && $node->localName === 'channel') {
                    return ['title' => $this->childText($node, 'title'), 'type' => 'rss'];
                }
            }
        }

        if ($root->localName === 'feed'
            && $root->namespaceURI === 'http://www.w3.org/2005/Atom') {
            return ['title' => $this->childText($root, 'title'), 'type' => 'atom'];
        }

        return null;
    }

    private function childText(DOMElement $parent, string $name): string
    {
        foreach ($parent->childNodes as $node) {
            if ($node instanceof DOMElement && $node->localName === $name) {
                return trim($node->textContent);
            }
        }

        return '';
    }
}
