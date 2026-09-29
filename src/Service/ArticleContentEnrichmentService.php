<?php

declare(strict_types=1);

namespace App\Service;

use App\Clock\Clock;
use App\Repository\ArticleRepository;
use Throwable;

final readonly class ArticleContentEnrichmentService
{
    public function __construct(
        private ArticleRepository $articles,
        private ArticlePageService $articlePages,
        private ExternalHtmlTextSanitizer $sanitizer,
        private Clock $clock,
    ) {}

    /**
     * @param null|callable(int, int): void $progress
     * @return array{total: int, repaired: int, extracted: int, empty: int, failed: int}
     */
    public function run(?callable $progress = null): array
    {
        $repaired = $this->repairEncodedPageContent();
        $candidates = $this->articles->pendingPageContentCandidates();
        $summary = [
            'total' => count($candidates),
            'repaired' => $repaired,
            'extracted' => 0,
            'empty' => 0,
            'failed' => 0,
        ];
        /** @var array<string, list<array{id: int, user_id: int, url: string}>> $byUrl */
        $byUrl = [];
        foreach ($candidates as $candidate) {
            $byUrl[$candidate['url']][] = $candidate;
        }
        $done = 0;
        foreach ($byUrl as $url => $sameUrlCandidates) {
            $now = $this->clock->now()->format('Y-m-d\TH:i:s\Z');
            $path = parse_url($url, PHP_URL_PATH);
            if (!is_string($path) || $path === '' || $path === '/') {
                foreach ($sameUrlCandidates as $candidate) {
                    $this->articles->markPageContentChecked($candidate['id'], $candidate['user_id'], $now);
                    ++$summary['empty'];
                }
                $done += count($sameUrlCandidates);
                if ($progress !== null) {
                    $progress($done, $summary['total']);
                }
                continue;
            }
            try {
                $page = $this->articlePages->fetch($url);
                foreach ($sameUrlCandidates as $candidate) {
                    if ($page->content === null) {
                        $this->articles->markPageContentChecked($candidate['id'], $candidate['user_id'], $now);
                        ++$summary['empty'];
                    } else {
                        $this->articles->setPageContent(
                            $candidate['id'],
                            $candidate['user_id'],
                            $page->content,
                            $now,
                        );
                        ++$summary['extracted'];
                    }
                }
            } catch (Throwable) {
                $summary['failed'] += count($sameUrlCandidates);
            }
            $done += count($sameUrlCandidates);
            if ($progress !== null) {
                $progress($done, $summary['total']);
            }
            usleep(100_000);
        }

        return $summary;
    }

    private function repairEncodedPageContent(): int
    {
        $repaired = 0;
        foreach ($this->articles->encodedPageContentCandidates() as $candidate) {
            $content = $candidate['content'];
            for ($pass = 0; $pass < 3; ++$pass) {
                $decoded = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($decoded === $content) {
                    break;
                }
                $content = $decoded;
            }
            $sanitized = $this->sanitizer->sanitize($content);
            if ($sanitized === null || $sanitized === $candidate['content']) {
                continue;
            }
            $now = $this->clock->now()->format('Y-m-d\TH:i:s\Z');
            if ($this->articles->setPageContent(
                $candidate['id'],
                $candidate['user_id'],
                $sanitized,
                $now,
            )) {
                ++$repaired;
            }
        }

        return $repaired;
    }
}
