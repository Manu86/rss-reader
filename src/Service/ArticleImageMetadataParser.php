<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\RemoteHttpException;
use App\Http\UrlResolver;
use DOMDocument;
use DOMElement;

final readonly class ArticleImageMetadataParser
{
    private const SUPPORTED_NAMES = [
        'og:image',
        'og:image:url',
        'og:image:secure_url',
        'twitter:image',
        'twitter:image:src',
    ];
    /**
     * Régions de la page dont les images ne peuvent pas illustrer l'article :
     * ce sont des éléments de navigation, pas du contenu éditorial.
     */
    private const CHROME_TAGS = ['header', 'nav', 'footer', 'aside'];
    /**
     * Les gabarits nomment leurs images décoratives. Le motif est volontairement
     * large : une image écartée à tort ne bloque pas la suite, la liste des
     * candidats continue.
     */
    private const DECORATIVE_NAME_PATTERN = '/(?:logo|icon|avatar|picto|sprite|spacer|blank|pixel|favicon|badge|button|glyph|banner)/i';
    /** Côté minimal déclaré, en pixels, pour une image de contenu. */
    private const MIN_DECLARED_SIDE = 200;
    private const MAX_CONTENT_CANDIDATES = 5;

    public function __construct(private UrlResolver $urls) {}

    /** @return list<string> */
    public function parse(string $html, string $documentUrl): array
    {
        $document = $this->document($html);
        if ($document === null) {
            return [];
        }

        $baseUrl = $this->baseUrl($document, $documentUrl);

        $candidates = [];
        foreach ($document->getElementsByTagName('meta') as $meta) {
            if (!$meta instanceof DOMElement) {
                continue;
            }
            $name = strtolower(trim($meta->getAttribute('property') ?: $meta->getAttribute('name')));
            if (!in_array($name, self::SUPPORTED_NAMES, true)) {
                continue;
            }
            $candidate = $this->resolve($baseUrl, $meta->getAttribute('content'));
            if ($candidate !== null && !in_array($candidate, $candidates, true)) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }

    /**
     * Images présentes dans le corps de la page, quand aucune métadonnée sociale
     * n'est publiée. Beaucoup de sites, notamment les gabarits les plus
     * anciens, n'exposent pas d'og:image alors que l'illustration de l'article
     * est bien dans la page. L'ordre du document est conservé : il suit l'ordre
     * de lecture, donc la première image du corps est en général l'illustration
     * principale. Le tri par taille déjouerait cet ordre, l'illustration étant
     * souvent la seule à ne pas déclarer ses dimensions.
     *
     * La taille réelle est verifiée au téléchargement, la sélection ici n'est
     * qu'un tri grossier qui évite de multiplier les requêtes.
     *
     * @return list<string>
     */
    public function parseContentImages(string $html, string $documentUrl): array
    {
        $document = $this->document($html);
        if ($document === null) {
            return [];
        }

        $baseUrl = $this->baseUrl($document, $documentUrl);

        $candidates = [];
        foreach ($document->getElementsByTagName('img') as $image) {
            if (!$image instanceof DOMElement || count($candidates) >= self::MAX_CONTENT_CANDIDATES) {
                continue;
            }
            if ($this->isChrome($image) || !$this->isLargeEnough($image)) {
                continue;
            }
            $source = $image->getAttribute('src');
            if (trim($source) === '') {
                $source = $image->getAttribute('data-src');
            }
            if ($this->looksDecorative($source)) {
                continue;
            }
            $candidate = $this->resolve($baseUrl, $source);
            if ($candidate !== null && !in_array($candidate, $candidates, true)) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
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
                $html,
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $loaded ? $document : null;
    }

    private function baseUrl(DOMDocument $document, string $documentUrl): string
    {
        foreach ($document->getElementsByTagName('base') as $base) {
            if (!$base instanceof DOMElement) {
                continue;
            }
            $resolved = $this->resolve($documentUrl, $base->getAttribute('href'));
            if ($resolved !== null) {
                return $resolved;
            }
            break;
        }

        return $documentUrl;
    }

    private function isChrome(DOMElement $image): bool
    {
        $node = $image->parentNode;
        while ($node instanceof DOMElement) {
            if (in_array(strtolower($node->nodeName), self::CHROME_TAGS, true)) {
                return true;
            }
            $node = $node->parentNode;
        }

        return false;
    }

    private function isLargeEnough(DOMElement $image): bool
    {
        $width = $image->getAttribute('width');
        $height = $image->getAttribute('height');
        if ($width === '' || $height === '') {
            // Une image de contenu garde souvent ses dimensions intrinsèques.
            return true;
        }
        if (preg_match('/^\d+$/', $width) !== 1 || preg_match('/^\d+$/', $height) !== 1) {
            return true;
        }

        return min((int) $width, (int) $height) >= self::MIN_DECLARED_SIDE;
    }

    private function looksDecorative(string $source): bool
    {
        if (trim($source) === '') {
            return true;
        }
        $path = parse_url(trim($source), PHP_URL_PATH);
        $name = basename(is_string($path) ? $path : $source);

        return $name !== '' && preg_match(self::DECORATIVE_NAME_PATTERN, $name) === 1;
    }

    private function resolve(string $baseUrl, string $candidate): ?string
    {
        if (trim($candidate) === '') {
            return null;
        }
        try {
            $resolved = $this->urls->resolve($baseUrl, $candidate);
        } catch (RemoteHttpException) {
            return null;
        }

        $scheme = strtolower((string) parse_url($resolved, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $resolved : null;
    }
}
