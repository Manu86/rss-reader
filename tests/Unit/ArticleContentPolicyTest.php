<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Service\ArticleContentPolicy;
use PHPUnit\Framework\TestCase;

final class ArticleContentPolicyTest extends TestCase
{
    public function testMarkupDoesNotCountTowardsTheVisibleLength(): void
    {
        self::assertSame(11, ArticleContentPolicy::visibleLength('<p>Bonjour <strong>ici</strong></p>'));
        self::assertFalse(ArticleContentPolicy::isSubstantial('<p>' . str_repeat('a', 199) . '</p>'));
        self::assertTrue(ArticleContentPolicy::isSubstantial('<p>' . str_repeat('a', 200) . '</p>'));
    }
}
