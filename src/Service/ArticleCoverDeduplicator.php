<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\StoredMedia;
use App\Storage\MediaStorage;
use App\Validation\UrlNormalizer;
use DOMDocument;
use DOMElement;
use DOMNode;
use Throwable;

/**
 * Retire du contenu stocké la répétition de l'illustration de l'article.
 * Trois niveaux de comparaison :
 *  - URL identique après normalisation ;
 *  - URL ne différant que par l'extension du fichier (og:image en .png,
 *    image de contenu en .jpg) ;
 *  - média identique : octets strictement égaux, ou grilles 8×8 de niveaux
 *    de gris quasi équivalentes quand le site publie le même visuel sous
 *    une résolution ou un encodage différents.
 *
 * Les seuils perceptuels restent prudents : les visuels réellement
 * identiques observés restent sous 16 d'écart, le premier cas
 * authentiquement distinct démarre à 21.
 */
final readonly class ArticleCoverDeduplicator
{
    /** Différence de niveau de gris par cellule au-delà de laquelle une cellule diverge. */
    private const GREY_TOLERANCE = 16;
    /** Nombre de cellules divergentes au-delà duquel le visuel est distinct. */
    private const MAX_PERCEPTUAL_DISTANCE = 16;
    private const GRID = 8;

    public function __construct(
        private RemoteMediaService $media,
        private MediaStorage $storage,
        private UrlNormalizer $urls,
    ) {}

    /**
     * URL sources présentes dans le contenu stocké.
     *
     * @return list<string>
     */
    public function imageSources(string $content): array
    {
        $document = $this->document($content);
        if ($document === null) {
            return [];
        }
        $sources = [];
        foreach ($document->getElementsByTagName('img') as $image) {
            if (!$image instanceof DOMElement) {
                continue;
            }
            $source = trim($image->getAttribute('src'));
            if ($source === '' || in_array($source, $sources, true)) {
                continue;
            }
            $sources[] = $source;
        }

        return $sources;
    }

    /**
     * Sources dont l'URL identifie le même visuel que l'illustration
     * retenue : URL strictement égale après normalisation, ou seulement
     * différente par l'extension du fichier.
     *
     * @param list<string> $sources
     * @return list<string>
     */
    public function sourcesMatchingUrl(?string $coverUrl, array $sources): array
    {
        if ($coverUrl === null || trim($coverUrl) === '') {
            return [];
        }
        $cover = $this->normalized($coverUrl);
        if ($cover === null) {
            return [];
        }
        $matches = [];
        foreach ($sources as $source) {
            $candidate = $this->normalized($source);
            if ($candidate === null) {
                continue;
            }
            if ($candidate === $cover
                || $this->withoutFileExtension($candidate) === $this->withoutFileExtension($cover)) {
                $matches[] = $source;
            }
        }

        return $matches;
    }

    /**
     * Sources dont le média reproduit la couverture déjà stockée : la
     * couverture est relue depuis le stockage local, sans nouveau
     * téléchargement, puis les candidates restantes sont comparées octet
     * pour octet ou perceptuellement.
     *
     * @param list<string> $sources
     * @param list<string> $preceding Sources déjà reconnues par URL.
     * @return list<string>
     */
    public function sourcesMatchingStoredMedia(
        int $userId,
        string $coverKey,
        array $sources,
        array $preceding = [],
    ): array {
        $cover = $this->storage->read($userId, $coverKey);
        if ($cover === null) {
            return $preceding;
        }

        return $this->sourcesMatchingMedia($userId, $cover, $sources, $preceding);
    }

    /**
     * Sources dont le média téléchargé reproduit le visuel stocké :
     * octets strictement identiques, ou grilles perceptuelles quasi
     * équivalentes. Chaque téléchargement est immédiatement libéré ; un
     * échec de téléchargement rejette la source sans interrompre la
     * comparaison.
     *
     * @param list<string> $sources
     * @param list<string> $preceding Sources déjà reconnues par URL.
     * @return list<string>
     */
    public function sourcesMatchingMedia(
        int $userId,
        ?StoredMedia $cover,
        array $sources,
        array $preceding = [],
    ): array {
        if ($cover === null) {
            return [];
        }
        $matches = $preceding;
        $coverHash = hash('sha256', $cover->content);
        $coverGrid = $this->greyLevels($cover->content);
        foreach ($sources as $source) {
            if (in_array($source, $matches, true)) {
                continue;
            }
            try {
                $key = $this->media->download($userId, $source);
            } catch (Throwable) {
                continue;
            }
            $candidate = $this->storage->read($userId, $key);
            $this->storage->delete($userId, $key);
            if ($candidate === null) {
                continue;
            }
            if (hash('sha256', $candidate->content) === $coverHash) {
                $matches[] = $source;
                continue;
            }
            $candidateGrid = $this->greyLevels($candidate->content);
            if ($coverGrid !== null && $candidateGrid !== null
                && $this->distance($coverGrid, $candidateGrid) <= self::MAX_PERCEPTUAL_DISTANCE) {
                $matches[] = $source;
            }
        }

        return $matches;
    }

    /**
     * Contenu sans les sources reconnues comme doublon du visuel.
     * Rien n'est réordonné ni réécrit quand aucune source ne correspond ;
     * le contenu retourné a alors la valeur d'origine, ce qui permet aux
     * appelants de détecter l'absence de modification.
     *
     * @param list<string> $sources
     */
    public function stripSources(string $content, array $sources): string
    {
        if ($sources === []) {
            return $content;
        }
        $document = $this->document($content);
        if ($document === null) {
            return $content;
        }
        $body = $document->getElementsByTagName('body')->item(0);
        if (!$body instanceof DOMNode) {
            return $content;
        }
        $targets = [];
        foreach ($sources as $source) {
            $target = $this->normalized($source) ?? trim($source);
            if (!in_array($target, $targets, true)) {
                $targets[] = $target;
            }
        }
        $images = [];
        foreach ($document->getElementsByTagName('img') as $image) {
            if ($image instanceof DOMElement) {
                $images[] = $image;
            }
        }
        $removed = false;
        foreach ($images as $image) {
            $source = trim($image->getAttribute('src'));
            $target = $this->normalized($source) ?? $source;
            if (in_array($target, $targets, true)) {
                $image->parentNode?->removeChild($image);
                $removed = true;
            }
        }
        if (!$removed) {
            return $content;
        }
        $html = '';
        foreach ($body->childNodes as $child) {
            $serialized = $document->saveHTML($child);
            if (is_string($serialized)) {
                $html .= $serialized;
            }
        }

        return trim($html);
    }

    private function normalized(string $source): ?string
    {
        try {
            return $this->urls->normalizeHttpUrl(trim($source), 'url');
        } catch (\App\Exception\ValidationException) {
            return null;
        }
    }

    private function withoutFileExtension(string $normalizedUrl): string
    {
        $path = parse_url($normalizedUrl, PHP_URL_PATH);
        if (!is_string($path)
            || preg_match('/\.(?:jpe?g|png|gif|webp|avif|ico)$/i', $path) !== 1) {
            return $normalizedUrl;
        }
        $stripped = preg_replace('/\.(?:jpe?g|png|gif|webp|avif|ico)$/i', '', $path);
        if (!is_string($stripped) || $stripped === $path) {
            return $normalizedUrl;
        }

        return str_replace($path, $stripped, $normalizedUrl);
    }

    private function document(string $content): ?DOMDocument
    {
        if (trim($content) === '') {
            return null;
        }
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML(
                '<html><head><meta charset="UTF-8"></head><body>' . $content . '</body></html>',
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $loaded ? $document : null;
    }

    /** @return null|list<int> 64 niveaux de gris 8×8 du visuel, null si non décodable. */
    private function greyLevels(string $bytes): ?array
    {
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return null;
        }
        $grid = imagecreatetruecolor(self::GRID, self::GRID);
        if ($grid === false) {
            imagedestroy($image);

            return null;
        }
        imagecopyresampled($grid, $image, 0, 0, 0, 0, self::GRID, self::GRID, imagesx($image), imagesy($image));
        imagefilter($grid, IMG_FILTER_GRAYSCALE);
        $levels = [];
        for ($y = 0; $y < self::GRID; ++$y) {
            for ($x = 0; $x < self::GRID; ++$x) {
                $levels[] = imagecolorat($grid, $x, $y) & 0xFF;
            }
        }
        imagedestroy($image);
        imagedestroy($grid);

        return $levels;
    }

    /**
     * Écart entre deux grilles de niveaux de gris : nombre de cellules
     * dont la différence dépasse la tolérance.
     *
     * @param list<int> $cover
     * @param list<int> $candidate
     */
    private function distance(array $cover, array $candidate): int
    {
        $distance = 0;
        foreach ($cover as $index => $level) {
            if (abs($level - $candidate[$index]) > self::GREY_TOLERANCE) {
                ++$distance;
            }
        }

        return $distance;
    }
}
