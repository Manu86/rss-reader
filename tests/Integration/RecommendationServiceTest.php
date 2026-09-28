<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\Model\Article;
use App\Repository\ArticleRepository;
use App\Repository\CategoryRepository;
use App\Service\RecommendationService;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Tests\Support\FrozenClock;

final class RecommendationServiceTest extends TestCase
{
    private const ALICE = 1;
    private const BOB = 2;
    private const CATEGORY_NEWS = 100;
    private const CATEGORY_ENERGY = 200;

    private PDO $pdo;
    private RecommendationService $recommendations;

    protected function setUp(): void
    {
        $this->pdo = ConnectionFactory::createMemory();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->insertUser(self::ALICE, 'alice');
        $this->insertUser(self::BOB, 'bob');
        $this->insertCategory(self::CATEGORY_NEWS, self::ALICE, 'Actualités');
        $this->insertCategory(self::CATEGORY_ENERGY, self::ALICE, 'Énergie');
        $this->insertFeed(10, self::ALICE, self::CATEGORY_NEWS);
        $this->insertFeed(11, self::ALICE, null);
        $this->insertFeed(20, self::ALICE, self::CATEGORY_ENERGY);
        // Suffisamment de sources pour que le plafond de 5 articles par flux
        // n'impose pas de reduire la liste dans les tests de volume.
        foreach (range(40, 50) as $feedId) {
            $this->insertFeed($feedId, self::ALICE, self::CATEGORY_NEWS);
        }
        $this->insertFeed(30, self::BOB, null);
        $this->recommendations = $this->serviceWithSeed(1);
    }

    public function testNoFavoriteHistoryProducesNoRecommendation(): void
    {
        $this->insertArticle(
            self::ALICE,
            10,
            title: 'Le photovoltaïque en Aude',
            publishedAt: '2026-09-20T08:00:00Z',
        );

        self::assertSame([], $this->recommendations->forUser(self::ALICE));
    }

    public function testFavoriteTagsDriveTheSelectionEvenWithAVeryLongFavoriteTitle(): void
    {
        $this->insertArticle(
            self::ALICE,
            10,
            title: 'Dans un long article éditorial sans aucun rapport avec l’énergie on évoque beaucoup '
                . 'de sujets très variés qui n’ont strictement rien à voir avec le reste',
            publishedAt: '2026-09-01T08:00:00Z',
            favorite: true,
            tags: ['photovoltaïque', 'citoyens'],
        );
        $matching = $this->insertArticle(
            self::ALICE,
            20,
            title: 'Les panneaux photovoltaïques gagnent du terrain',
            publishedAt: '2026-09-10T08:00:00Z',
        );
        $unrelated = $this->insertArticle(
            self::ALICE,
            20,
            title: 'Recette de saison : la betterave en hiver',
            publishedAt: '2026-09-19T08:00:00Z',
        );

        $ids = $this->ids($this->recommendations->forUser(self::ALICE));

        self::assertSame([$matching], $ids);
        self::assertNotContains($unrelated, $ids);
    }

    public function testArticlesMatchingOnlyStopWordsAreNotRecommended(): void
    {
        $this->insertArticle(
            self::ALICE,
            10,
            title: 'Nous avons donc une fois de plus dans la loi',
            publishedAt: '2026-09-01T08:00:00Z',
            favorite: true,
            tags: ['photovoltaïque'],
        );
        $decoy = $this->insertArticle(
            self::ALICE,
            20,
            title: 'Dans ce dossier, on parle d’un audit complet',
            publishedAt: '2026-09-19T08:00:00Z',
        );

        $ids = $this->ids($this->recommendations->forUser(self::ALICE));

        self::assertNotContains($decoy, $ids);
    }

    public function testTheClosestFtsMatchIsSelectedEvenWhenItIsOlder(): void
    {
        $this->insertArticle(
            self::ALICE,
            11,
            title: 'Le photovoltaïque en Aude',
            publishedAt: '2026-09-01T08:00:00Z',
            favorite: true,
        );
        $strongMatch = $this->insertArticle(
            self::ALICE,
            10,
            title: 'Photovoltaïque',
            publishedAt: '2026-09-02T08:00:00Z',
        );
        $weakMatch = $this->insertArticle(
            self::ALICE,
            20,
            title: 'Dossier spécial du mois',
            publishedAt: '2026-09-18T08:00:00Z',
            content: 'photovoltaïque ' . str_repeat('phrase de remplissage sans rapport ', 25),
        );

        $ids = $this->ids($this->recommendations->forUser(self::ALICE));

        // Le score décide de la sélection : le match le plus proche est retenu
        // malgré son âge. Seule la présentation suit la date.
        self::assertCount(2, $ids);
        self::assertContains($strongMatch, $ids);
        self::assertContains($weakMatch, $ids);
        self::assertSame([$weakMatch, $strongMatch], $ids);
    }

    public function testRecommendationsAreDisplayedFromTheMostRecentToTheOldest(): void
    {
        $this->insertArticle(
            self::ALICE,
            11,
            title: 'Le photovoltaïque en Aude',
            publishedAt: '2026-09-01T08:00:00Z',
            favorite: true,
        );
        $oldest = $this->insertArticle(
            self::ALICE,
            10,
            title: 'Photovoltaïque ancien',
            publishedAt: '2026-09-02T08:00:00Z',
        );
        $newest = $this->insertArticle(
            self::ALICE,
            20,
            title: 'Photovoltaïque récent',
            publishedAt: '2026-09-20T08:00:00Z',
        );
        $middle = $this->insertArticle(
            self::ALICE,
            40,
            title: 'Photovoltaïque intermédiaire',
            publishedAt: '2026-09-11T08:00:00Z',
        );

        $ids = $this->ids($this->recommendations->forUser(self::ALICE));

        self::assertSame([$newest, $middle, $oldest], $ids);
    }

    public function testReadFavoriteAndOtherUserArticlesAreNeverRecommended(): void
    {
        $this->insertArticle(
            self::ALICE,
            10,
            title: 'Le photovoltaïque en Aude',
            publishedAt: '2026-09-01T08:00:00Z',
            favorite: true,
        );
        $favorite = $this->insertArticle(
            self::ALICE,
            10,
            title: 'Photovoltaïque : un bilan',
            publishedAt: '2026-09-15T08:00:00Z',
            favorite: true,
        );
        $read = $this->insertArticle(
            self::ALICE,
            10,
            title: 'Photovoltaïque : déjà lu',
            publishedAt: '2026-09-16T08:00:00Z',
            read: true,
        );
        $bob = $this->insertArticle(
            self::BOB,
            30,
            title: 'Photovoltaïque chez bob',
            publishedAt: '2026-09-17T08:00:00Z',
        );
        $expected = $this->insertArticle(
            self::ALICE,
            20,
            title: 'Photovoltaïque : la suite',
            publishedAt: '2026-09-18T08:00:00Z',
        );

        $ids = $this->ids($this->recommendations->forUser(self::ALICE));

        self::assertSame([$expected], $ids);
        self::assertNotContains($favorite, $ids);
        self::assertNotContains($read, $ids);
        self::assertNotContains($bob, $ids);
    }

    public function testRecommendationsStayInsideTheRequestedCategory(): void
    {
        $this->insertArticle(
            self::ALICE,
            10,
            title: 'Le photovoltaïque en Aude',
            publishedAt: '2026-09-01T08:00:00Z',
            favorite: true,
        );
        $news = $this->insertArticle(
            self::ALICE,
            10,
            title: 'Photovoltaïque : la une',
            publishedAt: '2026-09-19T08:00:00Z',
        );
        $energy = $this->insertArticle(
            self::ALICE,
            20,
            title: 'Photovoltaïque : le dossier',
            publishedAt: '2026-09-18T08:00:00Z',
        );

        $ids = $this->ids($this->recommendations->forUser(self::ALICE, self::CATEGORY_NEWS));

        self::assertSame([$news], $ids);
        self::assertNotContains($energy, $ids);
    }

    public function testNoFeedOccupiesMoreThanFivePlaces(): void
    {
        $this->insertArticle(
            self::ALICE,
            11,
            title: 'Le photovoltaïque en Aude',
            publishedAt: '2026-09-01T08:00:00Z',
            favorite: true,
        );
        // Un seul flux produit 40 candidats éligibles.
        for ($index = 1; $index <= 40; $index++) {
            $this->insertArticle(
                self::ALICE,
                10,
                title: 'Dossier ' . $index,
                publishedAt: sprintf('2026-09-%02dT08:00:00Z', ($index % 28) + 1),
                author: 'Photovoltaïque ' . $index,
            );
        }

        $recommendations = $this->recommendations->forUser(self::ALICE);

        self::assertCount(5, $recommendations);
        self::assertSame([10], array_values(array_unique(array_map(
            static fn(Article $article): int => $article->feedId,
            $recommendations,
        ))));
    }

    public function testAFewFeedsReturnFewerThanFortyEightArticles(): void
    {
        $this->insertArticle(
            self::ALICE,
            11,
            title: 'Le photovoltaïque en Aude',
            publishedAt: '2026-09-01T08:00:00Z',
            favorite: true,
        );
        foreach ([10, 20] as $feedId) {
            for ($index = 1; $index <= 6; $index++) {
                $this->insertArticle(
                    self::ALICE,
                    $feedId,
                    title: 'Dossier ' . $feedId . '-' . $index,
                    publishedAt: sprintf('2026-09-%02dT08:00:00Z', $index),
                    author: 'Photovoltaïque ' . $feedId . $index,
                );
            }
        }

        // Deux sources et un plafond de 5 : la liste reste volontairement
        // courte, meme a 48 places.
        $recommendations = $this->recommendations->forUser(self::ALICE);

        self::assertCount(10, $recommendations);
        self::assertSame([10, 20], array_values(array_unique(array_map(
            static fn(Article $article): int => $article->feedId,
            $recommendations,
        ))));
    }

    public function testTheListNeverExceedsFortyEightArticles(): void
    {
        $this->insertArticle(
            self::ALICE,
            11,
            title: 'Le photovoltaïque en Aude',
            publishedAt: '2026-09-01T08:00:00Z',
            favorite: true,
        );
        $this->insertEquallyScoredCandidates(90);

        $recommendations = $this->recommendations->forUser(self::ALICE);

        self::assertCount(48, $recommendations);
    }

    public function testFortyEightArticlesAreDrawnFromTheRecommendationPool(): void
    {
        $this->insertArticle(
            self::ALICE,
            11,
            title: 'Le photovoltaïque en Aude',
            publishedAt: '2026-09-01T08:00:00Z',
            favorite: true,
        );
        // 13 sources x 5 places : le pool atteint 65 candidats, largement plus
        // que les 48 places affichees, donc le tirage Aleatoire reste visible.
        $this->insertEquallyScoredCandidates(65);

        $lists = [];
        for ($seed = 1; $seed <= 4; $seed++) {
            $ids = $this->ids($this->serviceWithSeed($seed)->forUser(self::ALICE));
            self::assertCount(48, $ids, 'seed ' . $seed);
            $lists[] = $ids;
        }

        self::assertGreaterThan(1, count(array_unique(array_map(
            static fn(array $ids): string => implode(',', $ids),
            $lists,
        ))));
    }

    public function testACallerCanRequestFewerArticlesThanTheView(): void
    {
        $this->insertArticle(
            self::ALICE,
            11,
            title: 'Le photovoltaïque en Aude',
            publishedAt: '2026-09-01T08:00:00Z',
            favorite: true,
        );
        $this->insertEquallyScoredCandidates(65);

        self::assertCount(48, $this->recommendations->forUser(self::ALICE));
        self::assertCount(24, $this->recommendations->forUser(self::ALICE, null, false, 24));
    }

    public function testRankingIsReproducibleForAGivenSeed(): void
    {
        $this->insertArticle(
            self::ALICE,
            11,
            title: 'Le photovoltaïque en Aude',
            publishedAt: '2026-09-01T08:00:00Z',
            favorite: true,
        );
        $this->insertEquallyScoredCandidates();

        $first = $this->ids($this->serviceWithSeed(7)->forUser(self::ALICE));
        $second = $this->ids($this->serviceWithSeed(7)->forUser(self::ALICE));

        self::assertSame($first, $second);
    }

    public function testSelectionChangesBetweenCallsSoTheSameArticlesAreNotAlwaysShown(): void
    {
        $this->insertArticle(
            self::ALICE,
            11,
            title: 'Le photovoltaïque en Aude',
            publishedAt: '2026-09-01T08:00:00Z',
            favorite: true,
        );
        // Plus de candidats que le pool, sur assez de sources pour le remplir
        // entierement : le bruit de score, puis le tirage, font varier la
        // selection d'un appel a l'autre.
        $feeds = [10, 20, 40, 41, 42, 43, 44, 45, 46, 47, 48, 49, 50];
        foreach ($feeds as $feedId) {
            for ($index = 1; $index <= 12; $index++) {
                $this->insertArticle(
                    self::ALICE,
                    $feedId,
                    title: 'Dossier ' . $feedId . '-' . $index,
                    publishedAt: sprintf('2026-09-%02dT08:00:00Z', (($feedId * $index) % 28) + 1),
                    author: 'Photovoltaïque ' . $feedId . $index,
                );
            }
        }

        $selections = [];
        for ($seed = 1; $seed <= 8; $seed++) {
            $ids = $this->ids($this->serviceWithSeed($seed)->forUser(self::ALICE));
            self::assertCount(48, $ids, 'seed ' . $seed);
            $selections[] = implode(',', $ids);
        }

        self::assertGreaterThan(1, count(array_unique($selections)));
    }

    public function testTheFreshnessBonusIsExhaustedAfterFifteenDays(): void
    {
        $this->insertArticle(
            self::ALICE,
            11,
            title: 'Le photovoltaïque en Aude',
            publishedAt: '2026-09-01T08:00:00Z',
            favorite: true,
        );
        $withinWindow = $this->insertArticle(
            self::ALICE,
            10,
            title: 'Photovoltaïque',
            publishedAt: '2026-09-23T08:00:00Z',
        );
        $beyondWindow = $this->insertArticle(
            self::ALICE,
            20,
            title: 'Photovoltaïque',
            publishedAt: '2026-09-13T08:00:00Z',
        );

        // Les deux candidats ont le meme score deterministe : le bonus de
        // fraicheur est epuise au-dela de 15 jours. Son amplitude maximale (1)
        // L'age n'exclut aucun candidat eligible : le bonus s'epuise au-dela de
        // 15 jours, il ne retire pas l'article de la liste.
        for ($seed = 1; $seed <= 20; $seed++) {
            $ids = $this->ids($this->serviceAt(new DateTimeImmutable('2026-09-28T12:00:00Z'), $seed)
                ->forUser(self::ALICE));
            self::assertContains($withinWindow, $ids, 'seed ' . $seed);
            self::assertContains($beyondWindow, $ids, 'seed ' . $seed);
        }

        // Le frais garde en plus un point de score, ce qui le fait passer devant
        // l'ancien malgre le bruit. L'ecart de fraicheur vaut exactement 1 point
        // ici, donc il domine les 0,6 de bruit cumules de deux articles.
        $ids = $this->ids($this->serviceAt(new DateTimeImmutable('2026-09-28T12:00:00Z'), 1)
            ->forUser(self::ALICE));
        self::assertSame([$withinWindow, $beyondWindow], $ids);
    }

    public function testTheCategoryBonusIsNeverOvercomeByTheJitter(): void
    {
        $this->insertArticle(
            self::ALICE,
            10,
            title: 'Le photovoltaïque en Aude',
            publishedAt: '2026-09-01T08:00:00Z',
            favorite: true,
        );
        $sameCategory = $this->insertArticle(
            self::ALICE,
            10,
            title: 'Dossier un',
            publishedAt: '2026-09-20T08:00:00Z',
            author: 'Photovoltaïque',
        );
        $otherCategory = $this->insertArticle(
            self::ALICE,
            20,
            title: 'Dossier deux',
            publishedAt: '2026-09-19T08:00:00Z',
            author: 'Photovoltaïque',
        );

        for ($seed = 1; $seed <= 20; $seed++) {
            $recommendations = $this->serviceWithSeed($seed)->forUser(self::ALICE);
            self::assertSame(
                [$sameCategory, $otherCategory],
                $this->ids($recommendations),
                'seed ' . $seed,
            );
        }
    }

    /**
     * Un article dont la seule raison de matcher est un terme retrouvé dans
     * l'auteur, alors qu'un autre candidat matche nettement le titre, ne doit
     * pas être recommandé : sa pertinence normalisée est quasi nulle.
     */
    public function testIncidentalAuthorMatchIsNotRecommended(): void
    {
        $this->insertArticle(
            self::ALICE,
            11,
            title: 'Le photovoltaïque en Aude',
            publishedAt: '2026-09-01T08:00:00Z',
            favorite: true,
        );
        $strong = $this->insertArticle(
            self::ALICE,
            10,
            title: 'Photovoltaïque en Aude : le bilan',
            publishedAt: '2026-09-18T08:00:00Z',
        );
        $incidental = $this->insertArticle(
            self::ALICE,
            20,
            title: 'Dossier du mois',
            publishedAt: '2026-09-19T08:00:00Z',
            author: 'Photovoltaïque',
        );

        $ids = $this->ids($this->recommendations->forUser(self::ALICE));

        self::assertSame([$strong], $ids);
        self::assertNotContains($incidental, $ids);
    }

    /**
     * Le bonus de tag (+2) doit suffire à franchir le seuil de 2 même quand la
     * pertinence FTS est négligeable. L'article témoin, identique sauf les
     * tags, doit rester exclu : c'est bien le tag qui fait la différence.
     */
    public function testASharedTagLiftsAnArticleAboveTheThreshold(): void
    {
        $this->insertArticle(
            self::ALICE,
            11,
            title: 'Le photovoltaïque en Aude',
            publishedAt: '2026-09-01T08:00:00Z',
            favorite: true,
            tags: ['photovoltaïque'],
        );
        $this->insertArticle(
            self::ALICE,
            10,
            title: 'Photovoltaïque en Aude : le bilan',
            publishedAt: '2026-09-18T08:00:00Z',
        );
        $tagged = $this->insertArticle(
            self::ALICE,
            20,
            title: 'Dossier du mois',
            publishedAt: '2026-09-19T08:00:00Z',
            author: 'Photovoltaïque',
            tags: ['photovoltaïque'],
        );
        $untagged = $this->insertArticle(
            self::ALICE,
            20,
            title: 'Autre dossier',
            publishedAt: '2026-09-20T08:00:00Z',
            author: 'Photovoltaïque',
        );

        $ids = $this->ids($this->recommendations->forUser(self::ALICE));

        self::assertContains($tagged, $ids);
        self::assertNotContains($untagged, $ids);
    }

    /**
     * Même principe avec la catégorie : un article de la catégorie d'un favori
     * est recommandé même si son seul match FTS est incident, contrairement à
     * un article équivalent situé dans une autre catégorie.
     */
    public function testTheFavoriteCategoryLiftsAnArticleAboveTheThreshold(): void
    {
        $this->insertArticle(
            self::ALICE,
            10,
            title: 'Le photovoltaïque en Aude',
            publishedAt: '2026-09-01T08:00:00Z',
            favorite: true,
        );
        $strong = $this->insertArticle(
            self::ALICE,
            20,
            title: 'Photovoltaïque en Aude : le bilan',
            publishedAt: '2026-09-18T08:00:00Z',
        );
        $sameCategory = $this->insertArticle(
            self::ALICE,
            10,
            title: 'Dossier du mois',
            publishedAt: '2026-09-19T08:00:00Z',
            author: 'Photovoltaïque',
        );
        $otherCategory = $this->insertArticle(
            self::ALICE,
            20,
            title: 'Autre dossier',
            publishedAt: '2026-09-20T08:00:00Z',
            author: 'Photovoltaïque',
        );

        $ids = $this->ids($this->recommendations->forUser(self::ALICE));

        self::assertEqualsCanonicalizing([$strong, $sameCategory], $ids);
        self::assertNotContains($otherCategory, $ids);
    }

    /**
     * Le seuil porte sur la partie déterministe du score : le bruit de
     * classement ne doit jamais faire entrer un article de justesse, ni en
     * sortir un article qui dépasse le seuil.
     */
    public function testThresholdIsNotAffectedByTheJitter(): void
    {
        $this->insertArticle(
            self::ALICE,
            11,
            title: 'Le photovoltaïque en Aude',
            publishedAt: '2026-09-01T08:00:00Z',
            favorite: true,
        );
        $this->insertArticle(
            self::ALICE,
            10,
            title: 'Photovoltaïque en Aude : le bilan',
            publishedAt: '2026-09-18T08:00:00Z',
        );
        $this->insertArticle(
            self::ALICE,
            20,
            title: 'Dossier du mois',
            publishedAt: '2026-09-19T08:00:00Z',
            author: 'Photovoltaïque',
        );
        $this->insertArticle(
            self::ALICE,
            20,
            title: 'Autre dossier',
            publishedAt: '2026-09-20T08:00:00Z',
            author: 'Photovoltaïque',
        );

        $reference = null;
        for ($seed = 1; $seed <= 12; $seed++) {
            $ids = $this->ids($this->serviceWithSeed($seed)->forUser(self::ALICE));
            self::assertCount(1, $ids, 'seed ' . $seed);
            $reference ??= $ids;
            self::assertSame($reference, $ids, 'seed ' . $seed);
        }
    }

    private function insertEquallyScoredCandidates(int $count = 5): void
    {
        $feedIds = array_values(array_unique(array_merge([10, 20], range(40, 50))));
        for ($index = 1; $index <= $count; $index++) {
            $this->insertArticle(
                self::ALICE,
                $feedIds[($index - 1) % count($feedIds)],
                title: 'Dossier ' . $index,
                publishedAt: sprintf('2026-09-%02dT08:00:00Z', ($index % 28) + 1),
                author: 'Photovoltaïque ' . $index,
            );
        }
    }

    private function serviceWithSeed(int $seed): RecommendationService
    {
        return new RecommendationService(
            new ArticleRepository($this->pdo),
            new CategoryRepository($this->pdo),
            new Randomizer(new Mt19937($seed)),
        );
    }

    private function serviceAt(DateTimeImmutable $now, int $seed): RecommendationService
    {
        return new RecommendationService(
            new ArticleRepository($this->pdo),
            new CategoryRepository($this->pdo),
            new Randomizer(new Mt19937($seed)),
            new FrozenClock($now),
        );
    }

    /**
     * @param list<Article> $articles
     * @return list<int>
     */
    private function ids(array $articles): array
    {
        return array_values(array_map(
            static fn(Article $article): int => $article->id,
            $articles,
        ));
    }

    private function insertUser(int $id, string $username): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO users (id, username, password_hash, created_at, updated_at) '
            . 'VALUES (:id, :username, :password_hash, :created_at, :updated_at)'
        );
        $statement->execute([
            'id' => $id,
            'username' => $username,
            'password_hash' => password_hash('password-for-tests', PASSWORD_DEFAULT),
            'created_at' => '2026-09-01T08:00:00Z',
            'updated_at' => '2026-09-01T08:00:00Z',
        ]);
    }

    private function insertCategory(int $id, int $userId, string $name): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO categories (id, user_id, name, created_at, updated_at) '
            . 'VALUES (:id, :user_id, :name, :created_at, :updated_at)'
        );
        $statement->execute([
            'id' => $id,
            'user_id' => $userId,
            'name' => $name,
            'created_at' => '2026-09-01T08:00:00Z',
            'updated_at' => '2026-09-01T08:00:00Z',
        ]);
    }

    private function insertFeed(int $id, int $userId, ?int $categoryId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO feeds '
            . '(id, user_id, name, feed_url, category_id, is_active, last_fetch_status, created_at, updated_at) '
            . 'VALUES (:id, :user_id, :name, :feed_url, :category_id, 1, :status, :created_at, :updated_at)'
        );
        $statement->execute([
            'id' => $id,
            'user_id' => $userId,
            'name' => 'Flux ' . $id,
            'feed_url' => 'https://feeds.test/' . $id . '.xml',
            'category_id' => $categoryId,
            'status' => 'never',
            'created_at' => '2026-09-01T08:00:00Z',
            'updated_at' => '2026-09-01T08:00:00Z',
        ]);
    }

    /**
     * @param list<string>|null $tags
     */
    private function insertArticle(
        int $userId,
        int $feedId,
        string $title,
        string $publishedAt,
        bool $favorite = false,
        ?array $tags = null,
        ?string $content = null,
        bool $read = false,
        ?string $author = null,
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO articles '
            . '(user_id, feed_id, title, author, published_at, discovered_at, content, tags, is_read, '
            . 'is_favorite, deduplication_hash, created_at, updated_at) '
            . 'VALUES (:user_id, :feed_id, :title, :author, :published_at, :discovered_at, :content, :tags, '
            . ':is_read, :is_favorite, :hash, :created_at, :updated_at)'
        );
        $statement->execute([
            'user_id' => $userId,
            'feed_id' => $feedId,
            'title' => $title,
            'author' => $author,
            'published_at' => $publishedAt,
            'discovered_at' => $publishedAt,
            'content' => $content,
            'tags' => $tags === null ? null : json_encode($tags, JSON_THROW_ON_ERROR),
            'is_read' => $read ? 1 : 0,
            'is_favorite' => $favorite ? 1 : 0,
            'hash' => hash('sha256', $userId . ':' . $feedId . ':' . $title),
            'created_at' => $publishedAt,
            'updated_at' => $publishedAt,
        ]);

        return (int) $this->pdo->lastInsertId();
    }
}
