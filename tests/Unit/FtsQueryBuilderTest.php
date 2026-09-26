<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exception\ValidationException;
use App\Service\FtsQueryBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FtsQueryBuilderTest extends TestCase
{
    public function testTextIsConvertedToBoundedLiteralPrefixTerms(): void
    {
        $builder = new FtsQueryBuilder();

        self::assertSame('"PHP"* "sqlite"*', $builder->build(' PHP + sqlite '));
        self::assertSame('"été"* "2026"*', $builder->build('été 2026'));
        self::assertSame('"mot"*', $builder->build('mot mot'));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidQueries(): iterable
    {
        yield 'empty' => ['   '];
        yield 'punctuation only' => ['" + -*'];
        yield 'too long' => [str_repeat('a', 201)];
        yield 'term too long' => [str_repeat('a', 65)];
        yield 'too many terms' => [implode(' ', array_map(
            static fn(int $value): string => 'term' . $value,
            range(1, 21),
        ))];
    }

    #[DataProvider('invalidQueries')]
    public function testInvalidQueriesAreRejected(string $query): void
    {
        $this->expectException(ValidationException::class);
        (new FtsQueryBuilder())->build($query);
    }
}
