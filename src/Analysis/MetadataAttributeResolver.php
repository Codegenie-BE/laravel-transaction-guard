<?php

declare(strict_types=1);

namespace Codegenie\TransactionGuard\Analysis;

final class MetadataAttributeResolver
{
    public static function hasClassAttribute(
        string $source,
        int $declarationOffset,
        FileContext $context,
        string $fqcn,
        string $fallback,
    ): bool {
        $prefix = substr($source, max(0, $declarationOffset - 4096), min(4096, $declarationOffset));
        if (preg_match('/(?:#\[(?:[^\]\"\']|\"[^\"]*\"|\'[^\']*\')*\]\s*)+$/s', $prefix, $match) !== 1) {
            return false;
        }

        $normalized = ltrim($fqcn, '\\');
        $aliases = ['\\'.$normalized];
        if (strcasecmp(ltrim($context->resolve($fallback), '\\'), $normalized) === 0) {
            $aliases[] = $fallback;
        }
        foreach ($context->imports as $alias => $import) {
            if (strcasecmp(ltrim($import, '\\'), $normalized) === 0) {
                $aliases[] = $alias;
            }
        }

        foreach (array_unique($aliases) as $alias) {
            if (preg_match('/#\[\s*'.preg_quote($alias, '/').'\b/i', $match[0]) === 1) {
                return true;
            }
        }

        return false;
    }
}
