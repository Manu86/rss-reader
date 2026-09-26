<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ValidationException;

final readonly class FtsQueryBuilder
{
    public function build(string $query): string
    {
        $query = trim($query);
        if ($query === '' || mb_strlen($query, 'UTF-8') > 200) {
            throw new ValidationException([
                'q' => 'La recherche doit contenir entre 1 et 200 caractères.',
            ]);
        }
        $matched = preg_match_all('/[\p{L}\p{N}_]+/u', $query, $matches);
        if ($matched === false || $matched === 0 || !isset($matches[0])) {
            throw new ValidationException(['q' => 'La recherche doit contenir au moins un terme.']);
        }
        /** @var list<string> $terms */
        $terms = array_values(array_unique($matches[0]));
        if (count($terms) > 20) {
            throw new ValidationException(['q' => 'La recherche contient trop de termes.']);
        }
        foreach ($terms as $term) {
            if (mb_strlen($term, 'UTF-8') > 64) {
                throw new ValidationException(['q' => 'Un terme de recherche est trop long.']);
            }
        }

        return implode(' ', array_map(
            static fn(string $term): string => '"' . $term . '"*',
            $terms,
        ));
    }
}
