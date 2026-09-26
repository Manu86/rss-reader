<?php

declare(strict_types=1);

namespace App\Service;

use App\Clock\Clock;
use App\Model\Feed;
use App\Repository\CategoryRepository;
use App\Repository\FeedRepository;
use DOMDocument;
use DOMElement;

final readonly class OpmlExporter
{
    public function __construct(
        private CategoryRepository $categories,
        private FeedRepository $feeds,
        private Clock $clock,
    ) {}

    public function export(int $userId): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;
        $opml = $document->createElement('opml');
        $opml->setAttribute('version', '2.0');
        $document->appendChild($opml);
        $head = $document->createElement('head');
        $head->appendChild($document->createElement('title', 'Abonnements RSS Reader'));
        $head->appendChild($document->createElement(
            'dateCreated',
            $this->clock->now()->format(DATE_RFC2822),
        ));
        $opml->appendChild($head);
        $body = $document->createElement('body');
        $opml->appendChild($body);

        $categoryElements = [];
        foreach ($this->categories->listOwned($userId) as $category) {
            $outline = $document->createElement('outline');
            $outline->setAttribute('text', $category->name);
            $outline->setAttribute('title', $category->name);
            $body->appendChild($outline);
            $categoryElements[$category->id] = $outline;
        }
        foreach ($this->feeds->listOwned($userId, null, null) as $feed) {
            $parent = $feed->categoryId === null
                ? $body
                : ($categoryElements[$feed->categoryId] ?? $body);
            $parent->appendChild($this->feedOutline($document, $feed));
        }

        $xml = $document->saveXML();
        if (!is_string($xml)) {
            throw new \RuntimeException('L’export OPML n’a pas pu être généré.');
        }

        return $xml;
    }

    private function feedOutline(DOMDocument $document, Feed $feed): DOMElement
    {
        $outline = $document->createElement('outline');
        $outline->setAttribute('type', 'rss');
        $outline->setAttribute('text', $feed->name);
        $outline->setAttribute('title', $feed->name);
        $outline->setAttribute('xmlUrl', $feed->feedUrl);
        if ($feed->siteUrl !== null) {
            $outline->setAttribute('htmlUrl', $feed->siteUrl);
        }

        return $outline;
    }
}
