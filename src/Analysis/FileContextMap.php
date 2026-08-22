<?php

declare(strict_types=1);

namespace Codegenie\TransactionGuard\Analysis;

/**
 * Resolves namespace/import context by source offset without executing code.
 *
 * @phpstan-type ContextToken array{id:int|null,text:string,line:int,offset:int}
 * @phpstan-type ContextRange array{start:int,end:int,context:FileContext}
 */
final class FileContextMap
{
    /** @param list<ContextRange> $ranges */
    private function __construct(private readonly array $ranges) {}

    /**
     * @param  list<ContextToken>  $tokens
     */
    public static function fromTokens(array $tokens, int $sourceLength): self
    {
        $namespaceDeclarations = self::namespaceDeclarations($tokens, $sourceLength);
        if ($namespaceDeclarations === []) {
            return new self([[
                'start' => 0,
                'end' => $sourceLength,
                'context' => new FileContext('', self::importsForRange($tokens, 0, $sourceLength, 0)),
            ]]);
        }

        $ranges = [];
        $firstNamespaceOffset = $namespaceDeclarations[0]['declaration'];
        if ($firstNamespaceOffset > 0) {
            $ranges[] = [
                'start' => 0,
                'end' => $firstNamespaceOffset,
                'context' => new FileContext('', self::importsForRange($tokens, 0, $firstNamespaceOffset, 0)),
            ];
        }

        foreach ($namespaceDeclarations as $declaration) {
            $ranges[] = [
                'start' => $declaration['start'],
                'end' => $declaration['end'],
                'context' => new FileContext(
                    $declaration['namespace'],
                    self::importsForRange($tokens, $declaration['start'], $declaration['end'], $declaration['baseDepth']),
                ),
            ];
        }

        return new self($ranges);
    }

    public function at(int $offset): FileContext
    {
        foreach ($this->ranges as $range) {
            if ($offset >= $range['start'] && $offset < $range['end']) {
                return $range['context'];
            }
        }

        $last = array_key_last($this->ranges);

        return $last !== null ? $this->ranges[$last]['context'] : new FileContext('', []);
    }

    /** @return list<FileContext> */
    public function contexts(): array
    {
        $contexts = [];
        $seen = [];

        foreach ($this->ranges as $range) {
            $context = $range['context'];
            $key = $context->namespace.'|'.serialize($context->imports);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $contexts[] = $context;
        }

        return $contexts;
    }

    /**
     * @param  list<ContextToken>  $tokens
     * @return list<array{declaration:int,start:int,end:int,namespace:string,baseDepth:int}>
     */
    private static function namespaceDeclarations(array $tokens, int $sourceLength): array
    {
        $declarations = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (($tokens[$i]['id'] ?? null) !== T_NAMESPACE) {
                continue;
            }

            $namespace = '';
            $terminator = null;
            for ($j = $i + 1; $j < $count; $j++) {
                if (in_array($tokens[$j]['text'], [';', '{'], true)) {
                    $terminator = $j;
                    break;
                }
                if (self::isNameToken($tokens[$j]['id'] ?? null)) {
                    $namespace .= $tokens[$j]['text'];
                }
            }
            if ($terminator === null) {
                continue;
            }

            $namespace = trim($namespace, " \t\n\r\0\x0B\\");
            if ($tokens[$terminator]['text'] === '{') {
                $close = self::matchingDelimiter($tokens, $terminator, '{', '}');
                if ($close === null) {
                    continue;
                }
                $declarations[] = [
                    'declaration' => $tokens[$i]['offset'],
                    'start' => $tokens[$terminator]['offset'] + 1,
                    'end' => $tokens[$close]['offset'],
                    'namespace' => $namespace,
                    'baseDepth' => 1,
                ];
                $i = $close;

                continue;
            }

            $nextNamespaceOffset = $sourceLength;
            for ($j = $terminator + 1; $j < $count; $j++) {
                if (($tokens[$j]['id'] ?? null) === T_NAMESPACE) {
                    $nextNamespaceOffset = $tokens[$j]['offset'];
                    break;
                }
            }
            $declarations[] = [
                'declaration' => $tokens[$i]['offset'],
                'start' => $tokens[$terminator]['offset'] + 1,
                'end' => $nextNamespaceOffset,
                'namespace' => $namespace,
                'baseDepth' => 0,
            ];
        }

        return $declarations;
    }

    /**
     * @param  list<ContextToken>  $tokens
     * @return array<string, string>
     */
    private static function importsForRange(array $tokens, int $startOffset, int $endOffset, int $baseDepth): array
    {
        $imports = [];
        $depth = 0;
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token['offset'] < $startOffset) {
                if ($token['text'] === '{') {
                    $depth++;
                } elseif ($token['text'] === '}') {
                    $depth = max(0, $depth - 1);
                }

                continue;
            }
            if ($token['offset'] >= $endOffset) {
                break;
            }

            if (($token['id'] ?? null) === T_USE && $depth === $baseDepth) {
                $clause = '';
                for ($j = $i + 1; $j < $count && $tokens[$j]['offset'] < $endOffset; $j++) {
                    if ($tokens[$j]['text'] === ';') {
                        $i = $j;
                        break;
                    }
                    $clause .= $tokens[$j]['text'];
                }
                foreach (self::parseUseClause($clause) as $alias => $fqcn) {
                    $imports[$alias] = $fqcn;
                }

                continue;
            }

            if ($token['text'] === '{') {
                $depth++;
            } elseif ($token['text'] === '}') {
                $depth = max(0, $depth - 1);
            }
        }

        return $imports;
    }

    /** @return array<string, string> */
    private static function parseUseClause(string $clause): array
    {
        $clause = trim($clause);
        if ($clause === '' || str_starts_with($clause, 'function ') || str_starts_with($clause, 'const ')) {
            return [];
        }

        $result = [];
        if (preg_match('/^(.+?)\\\{(.+)\}$/', $clause, $matches) === 1) {
            $prefix = trim($matches[1], '\\').'\\';
            foreach (explode(',', $matches[2]) as $part) {
                self::appendUse($result, $prefix.trim($part));
            }

            return $result;
        }

        foreach (explode(',', $clause) as $part) {
            self::appendUse($result, trim($part));
        }

        return $result;
    }

    /** @param array<string, string> $result */
    private static function appendUse(array &$result, string $part): void
    {
        if ($part === '') {
            return;
        }
        if (preg_match('/^(.+?)\s+as\s+([A-Za-z_][A-Za-z0-9_]*)$/i', $part, $matches) === 1) {
            $fqcn = ltrim(trim($matches[1]), '\\');
            $alias = $matches[2];
        } else {
            $fqcn = ltrim(trim($part), '\\');
            $segments = explode('\\', $fqcn);
            $alias = end($segments) ?: $fqcn;
        }
        $result[$alias] = $fqcn;
    }

    private static function isNameToken(?int $id): bool
    {
        return in_array($id, array_filter([
            T_STRING,
            defined('T_NAME_QUALIFIED') ? T_NAME_QUALIFIED : null,
            defined('T_NAME_FULLY_QUALIFIED') ? T_NAME_FULLY_QUALIFIED : null,
            defined('T_NAME_RELATIVE') ? T_NAME_RELATIVE : null,
            defined('T_NS_SEPARATOR') ? T_NS_SEPARATOR : null,
        ]), true);
    }

    /** @param list<ContextToken> $tokens */
    private static function matchingDelimiter(array $tokens, int $open, string $openText, string $closeText): ?int
    {
        $depth = 0;
        $count = count($tokens);
        for ($i = $open; $i < $count; $i++) {
            if ($tokens[$i]['text'] === $openText) {
                $depth++;
            } elseif ($tokens[$i]['text'] === $closeText && --$depth === 0) {
                return $i;
            }
        }

        return null;
    }
}
