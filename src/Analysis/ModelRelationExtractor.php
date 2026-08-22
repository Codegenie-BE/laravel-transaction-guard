<?php

declare(strict_types=1);

namespace Codegenie\TransactionGuard\Analysis;

final class ModelRelationExtractor
{
    /** @return array<string, string> relation method => resolved related class */
    public static function extract(string $source, int $start, int $end, FileContext $context): array
    {
        $body = substr($source, $start, max(0, $end - $start));
        $relationMethods = 'hasOne|hasMany|belongsTo|belongsToMany|morphOne|morphMany|morphToMany|morphedByMany';
        $pattern = '/function\s+(?<name>[A-Za-z_][A-Za-z0-9_]*)\s*\([^)]*\)\s*(?::\s*[^\{]+)?\{(?:(?!\bfunction\b).)*?\$this\s*->\s*(?:'.$relationMethods.')\s*\(\s*(?<class>\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*)\s*::\s*class/is';
        if (preg_match_all($pattern, $body, $matches, PREG_SET_ORDER) === false) {
            return [];
        }

        $relations = [];
        foreach ($matches as $match) {
            $relations[strtolower($match['name'])] = $context->resolve($match['class']);
        }

        return $relations;
    }
}
