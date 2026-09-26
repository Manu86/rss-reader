<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ValidationException;
use DOMDocument;
use DOMElement;

final class OpmlParser
{
    private const MAX_OUTLINES = 1_000;
    private const MAX_SUBSCRIPTIONS = 100;

    private int $outlineCount = 0;

    /** @var array<string, string> */
    private array $categories = [];

    /** @var list<array{feed_url: string, name: string|null, category_name: string|null}> */
    private array $subscriptions = [];

    /** @return array{categories: list<string>, subscriptions: list<array{feed_url: string, name: string|null, category_name: string|null}>} */
    public function parse(string $xml): array
    {
        $this->outlineCount = 0;
        $this->categories = [];
        $this->subscriptions = [];
        if (trim($xml) === ''
            || stripos($xml, '<!DOCTYPE') !== false
            || stripos($xml, '<!ENTITY') !== false) {
            throw $this->invalid();
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
        if (!$loaded || !$document->documentElement instanceof DOMElement
            || $document->documentElement->localName !== 'opml') {
            throw $this->invalid();
        }
        $body = $this->firstChild($document->documentElement, 'body');
        if ($body === null) {
            throw $this->invalid();
        }

        foreach ($this->outlineChildren($body) as $outline) {
            $this->walk($outline, null);
        }

        return [
            'categories' => array_values($this->categories),
            'subscriptions' => $this->subscriptions,
        ];
    }

    private function walk(DOMElement $outline, ?string $categoryName): void
    {
        ++$this->outlineCount;
        if ($this->outlineCount > self::MAX_OUTLINES) {
            throw new ValidationException(['file' => 'Le fichier OPML contient trop d’éléments.']);
        }

        $feedUrl = trim($outline->getAttribute('xmlUrl'));
        if ($feedUrl !== '') {
            if (count($this->subscriptions) >= self::MAX_SUBSCRIPTIONS) {
                throw new ValidationException(['file' => 'Le fichier OPML contient plus de 100 abonnements.']);
            }
            $name = trim($outline->getAttribute('title'));
            if ($name === '') {
                $name = trim($outline->getAttribute('text'));
            }
            $this->subscriptions[] = [
                'feed_url' => $feedUrl,
                'name' => $name === '' ? null : $name,
                'category_name' => $categoryName,
            ];

            return;
        }

        $name = trim($outline->getAttribute('title'));
        if ($name === '') {
            $name = trim($outline->getAttribute('text'));
        }
        if ($name !== '') {
            $categoryName = $name;
            $key = mb_strtolower($name, 'UTF-8');
            $this->categories[$key] ??= $name;
        }
        foreach ($this->outlineChildren($outline) as $child) {
            $this->walk($child, $categoryName);
        }
    }

    private function firstChild(DOMElement $parent, string $name): ?DOMElement
    {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $name) {
                return $child;
            }
        }

        return null;
    }

    /** @return list<DOMElement> */
    private function outlineChildren(DOMElement $parent): array
    {
        $children = [];
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === 'outline') {
                $children[] = $child;
            }
        }

        return $children;
    }

    private function invalid(): ValidationException
    {
        return new ValidationException(['file' => 'Le fichier OPML est invalide ou non sécurisé.']);
    }
}
