<?php

declare(strict_types=1);

namespace Codegenie\TransactionGuard\Analysis;

final class ClassMetadataIndex
{
    /** @var array<string, ClassMetadata> */
    private array $classes = [];

    /** @var array<string, FileContext> */
    private array $contexts = [];

    /** @var array<string, string> Known connection name or @dynamic when a route connection is not statically resolvable. */
    private array $queueRouteConnections = [];

    /** @param list<string> $files */
    public static function fromFiles(array $files): self
    {
        $index = new self;

        foreach ($files as $file) {
            $index->indexFile($file);
        }

        $index->resolveInheritedInterfaces();

        return $index;
    }

    public function metadata(string $class): ?ClassMetadata
    {
        return $this->classes[strtolower(ltrim($class, '\\'))] ?? null;
    }

    public function contextFor(string $file): FileContext
    {
        return $this->contexts[$file] ?? new FileContext('', []);
    }

    public function queueRouteConnection(string $class): ?string
    {
        $class = ltrim($class, '\\');
        $exactKey = strtolower($class);
        if (array_key_exists($exactKey, $this->queueRouteConnections)) {
            return $this->queueRouteConnections[$exactKey];
        }

        // Laravel resolves class parents before interfaces. Preserve that ordering for
        // statically known parent classes, then conservatively resolve interfaces.
        $seen = [];
        $current = $this->metadata($class);
        while ($current?->parent !== null) {
            $parent = ltrim($current->parent, '\\');
            $key = strtolower($parent);
            if (isset($seen[$key])) {
                break;
            }
            $seen[$key] = true;

            if (array_key_exists($key, $this->queueRouteConnections)) {
                return $this->queueRouteConnections[$key];
            }

            $current = $this->metadata($parent);
        }

        $metadata = $this->metadata($class);
        if ($metadata === null) {
            return null;
        }

        $resolved = [];
        foreach ($metadata->interfaces as $interface) {
            $key = strtolower(ltrim($interface, '\\'));
            if (array_key_exists($key, $this->queueRouteConnections)) {
                $resolved[] = $this->queueRouteConnections[$key];
            }
        }

        $resolved = array_values(array_unique($resolved));
        if ($resolved === []) {
            return null;
        }

        // Interface enumeration order can be difficult to prove statically when
        // inheritance is involved; differing routes are therefore treated as dynamic.
        return count($resolved) === 1 ? $resolved[0] : '@dynamic';
    }

    public function indexFile(string $file): void
    {
        $source = @file_get_contents($file);
        if ($source === false) {
            return;
        }

        $tokens = $this->tokens($source);
        $context = $this->parseContext($tokens);
        $this->contexts[$file] = $context;
        $this->indexQueueRoutes($source, $tokens, $context);

        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if (($tokens[$i]['id'] ?? null) !== T_CLASS) {
                continue;
            }

            $previous = $this->previousSignificant($tokens, $i - 1);
            if ($previous !== null && ($tokens[$previous]['id'] ?? null) === T_DOUBLE_COLON) {
                continue;
            }

            $nameIndex = $this->nextTokenOfType($tokens, $i + 1, T_STRING);
            if ($nameIndex === null) {
                continue;
            }

            $className = $tokens[$nameIndex]['text'];
            $interfaces = [];
            $parent = null;
            $openBrace = null;
            $mode = null;
            $buffer = '';

            for ($j = $nameIndex + 1; $j < $count; $j++) {
                $token = $tokens[$j];

                if ($token['text'] === '{') {
                    if ($mode === 'implements' && trim($buffer) !== '') {
                        $interfaces = array_merge($interfaces, $this->parseNameList($buffer, $context));
                    } elseif ($mode === 'extends' && trim($buffer) !== '') {
                        $parent = $this->parseSingleName($buffer, $context);
                    }
                    $openBrace = $j;
                    break;
                }

                if (($token['id'] ?? null) === T_IMPLEMENTS) {
                    if ($mode === 'extends' && trim($buffer) !== '') {
                        $parent = $this->parseSingleName($buffer, $context);
                    }
                    $mode = 'implements';
                    $buffer = '';
                    continue;
                }

                if (($token['id'] ?? null) === T_EXTENDS) {
                    if ($mode === 'implements' && trim($buffer) !== '') {
                        $interfaces = array_merge($interfaces, $this->parseNameList($buffer, $context));
                    }
                    $mode = 'extends';
                    $buffer = '';
                    continue;
                }

                if (in_array($mode, ['implements', 'extends'], true)) {
                    $buffer .= $token['text'];
                }
            }

            if ($openBrace === null) {
                continue;
            }

            $closeBrace = $this->matchingBrace($tokens, $openBrace);
            if ($closeBrace === null) {
                continue;
            }

            [$afterCommit, $beforeCommit, $queueConnection] = $this->constructorCommitBehavior($tokens, $openBrace + 1, $closeBrace - 1, $source);
            $fqcn = $context->namespace !== '' ? $context->namespace.'\\'.$className : $className;

            $this->classes[strtolower($fqcn)] = new ClassMetadata(
                name: $fqcn,
                interfaces: array_values(array_unique($interfaces)),
                parent: $parent,
                constructorAfterCommit: $afterCommit,
                constructorBeforeCommit: $beforeCommit,
                constructorQueueConnection: $queueConnection,
            );

            $i = $closeBrace;
        }
    }

    /**
     * @param list<array{id:int|null,text:string,line:int,offset:int}> $tokens
     */
    private function parseContext(array $tokens): FileContext
    {
        $namespace = '';
        $imports = [];
        $braceDepth = 0;
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token['text'] === '{') {
                $braceDepth++;
            } elseif ($token['text'] === '}') {
                $braceDepth = max(0, $braceDepth - 1);
            }

            if (($token['id'] ?? null) === T_NAMESPACE) {
                $name = '';
                for ($j = $i + 1; $j < $count; $j++) {
                    if (in_array($tokens[$j]['text'], [';', '{'], true)) {
                        break;
                    }
                    if ($this->isNameToken($tokens[$j]['id'] ?? null)) {
                        $name .= $tokens[$j]['text'];
                    }
                }
                $namespace = trim($name, " \\t\\n\\r\\0\\x0B\\\\");
                continue;
            }

            // Standard Laravel files use semicolon namespaces, so namespace imports are at depth 0.
            if (($token['id'] ?? null) === T_USE && $braceDepth === 0) {
                $clause = '';
                for ($j = $i + 1; $j < $count; $j++) {
                    if ($tokens[$j]['text'] === ';') {
                        $i = $j;
                        break;
                    }
                    $clause .= $tokens[$j]['text'];
                }

                foreach ($this->parseUseClause($clause) as $alias => $fqcn) {
                    $imports[$alias] = $fqcn;
                }
            }
        }

        return new FileContext($namespace, $imports);
    }

    /** @return array<string, string> */
    private function parseUseClause(string $clause): array
    {
        $clause = trim($clause);
        if ($clause === '' || str_starts_with($clause, 'function ') || str_starts_with($clause, 'const ')) {
            return [];
        }

        $result = [];

        // Basic grouped imports: Foo\\Bar\\{Baz, Qux as Alias}
        if (preg_match('/^(.+?)\\\\\{(.+)\}$/', $clause, $matches) === 1) {
            $prefix = trim($matches[1], '\\').'\\';
            foreach (explode(',', $matches[2]) as $part) {
                $this->appendUse($result, $prefix.trim($part));
            }

            return $result;
        }

        foreach (explode(',', $clause) as $part) {
            $this->appendUse($result, trim($part));
        }

        return $result;
    }

    /** @param array<string, string> $result */
    private function appendUse(array &$result, string $part): void
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

    private function parseSingleName(string $buffer, FileContext $context): ?string
    {
        $name = preg_replace('/\s+/', '', $buffer) ?? $buffer;

        return $name === '' ? null : $context->resolve($name);
    }

    /** @return list<string> */
    private function parseNameList(string $buffer, FileContext $context): array
    {
        $names = [];
        foreach (explode(',', $buffer) as $name) {
            $name = preg_replace('/\s+/', '', $name) ?? $name;
            if ($name !== '') {
                $names[] = $context->resolve($name);
            }
        }

        return $names;
    }

    /**
     * @param list<array{id:int|null,text:string,line:int,offset:int}> $tokens
     * @return array{bool,bool,string|null}
     */
    private function constructorCommitBehavior(array $tokens, int $start, int $end, string $source): array
    {
        for ($i = $start; $i <= $end; $i++) {
            if (($tokens[$i]['id'] ?? null) !== T_FUNCTION) {
                continue;
            }

            $nameIndex = $this->nextTokenOfType($tokens, $i + 1, T_STRING, $end);
            if ($nameIndex === null || strcasecmp($tokens[$nameIndex]['text'], '__construct') !== 0) {
                continue;
            }

            $openBrace = $this->nextText($tokens, $nameIndex + 1, '{', $end);
            if ($openBrace === null) {
                return [false, false, null];
            }

            $closeBrace = $this->matchingBrace($tokens, $openBrace, $end);
            if ($closeBrace === null) {
                return [false, false, null];
            }

            $offset = $tokens[$openBrace]['offset'];
            $length = ($tokens[$closeBrace]['offset'] + strlen($tokens[$closeBrace]['text'])) - $offset;
            $body = substr($source, $offset, $length);

            $connection = null;
            if (preg_match('/\$this\s*->\s*onConnection\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $body, $match) === 1) {
                $connection = $match[1];
            } elseif (preg_match('/\$this\s*->\s*onConnection\s*\(/', $body) === 1) {
                $connection = '@dynamic';
            }

            return [
                preg_match('/\$this\s*->\s*afterCommit\s*\(/', $body) === 1,
                preg_match('/\$this\s*->\s*beforeCommit\s*\(/', $body) === 1,
                $connection,
            ];
        }

        return [false, false, null];
    }

    /** @param list<array{id:int|null,text:string,line:int,offset:int}> $tokens */
    private function indexQueueRoutes(string $source, array $tokens, FileContext $context): void
    {
        $aliases = ['Queue'];
        foreach ($context->imports as $alias => $import) {
            if (strcasecmp(ltrim($import, '\\'), 'Illuminate\\Support\\Facades\\Queue') === 0) {
                $aliases[] = $alias;
            }
        }

        foreach (array_values(array_unique($aliases)) as $alias) {
            $pattern = '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::\s*route\s*\(/i';
            $ok = preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE);
            if ($ok === false || $ok === 0) {
                continue;
            }

            foreach ($matches[0] as [$matched, $offset]) {
                if ($this->offsetIsNonCode($tokens, $offset)) {
                    continue;
                }
                $open = $this->tokenIndexAtOrAfterOffset($tokens, $offset + strlen($matched) - 1, '(');
                if ($open === null) {
                    continue;
                }
                $close = $this->matchingDelimiter($tokens, $open, '(', ')');
                if ($close === null) {
                    continue;
                }

                $argsStart = $tokens[$open]['offset'] + 1;
                $args = substr($source, $argsStart, max(0, $tokens[$close]['offset'] - $argsStart));
                $this->parseQueueRouteArguments($args, $context);
            }
        }
    }

    private function parseQueueRouteArguments(string $arguments, FileContext $context): void
    {
        $arguments = trim($arguments);
        if ($arguments === '') {
            return;
        }

        if (str_starts_with($arguments, '[')) {
            if (preg_match_all('/(?P<class>\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*)\s*::\s*class\s*=>\s*(?P<value>\[[^\]]*\]|[\'\"][^\'\"]*[\'\"])/s', $arguments, $entries, PREG_SET_ORDER) === false) {
                return;
            }
            foreach ($entries as $entry) {
                $target = $context->resolve($entry['class']);
                $value = trim($entry['value']);
                if (! str_starts_with($value, '[')) {
                    continue; // queue-only route, default connection remains unchanged
                }
                // Laravel 13's published queue-routing documentation and the current
                // framework implementation disagree on the positional order of route
                // array values. A safety analyzer must not guess across that unstable
                // contract, so array routes with two values are treated as dynamic.
                $this->queueRouteConnections[strtolower(ltrim($target, '\\'))] = '@dynamic';
            }

            return;
        }

        $parts = $this->splitTopLevelArguments($arguments);
        if ($parts === [] || preg_match('/^\s*(\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*)\s*::\s*class\s*$/', $parts[0], $match) !== 1) {
            return;
        }

        $target = $context->resolve($match[1]);
        $connection = null;
        $connectionSpecified = false;

        foreach (array_slice($parts, 1) as $part) {
            if (preg_match('/^\s*connection\s*:\s*(.+)$/is', $part, $named) === 1) {
                $connectionSpecified = true;
                $value = trim($named[1]);
                if (strcasecmp($value, 'null') === 0) {
                    return;
                }
                $connection = $this->literalString($value) ?? '@dynamic';
                break;
            }
        }

        if (! $connectionSpecified && isset($parts[2])) {
            $connectionSpecified = true;
            $value = trim($parts[2]);
            if (strcasecmp($value, 'null') === 0) {
                return;
            }
            $connection = $this->literalString($value) ?? '@dynamic';
        }

        if ($connectionSpecified && $connection !== null) {
            $this->queueRouteConnections[strtolower(ltrim($target, '\\'))] = $connection;
        }
    }

    /** @return list<string> */
    private function splitTopLevelArguments(string $source): array
    {
        $parts = [];
        $start = 0;
        $paren = $bracket = $brace = 0;
        $quote = null;
        $escaped = false;
        $length = strlen($source);

        for ($i = 0; $i < $length; $i++) {
            $char = $source[$i];
            if ($quote !== null) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }
                if ($char === '\\') {
                    $escaped = true;
                    continue;
                }
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '\'' || $char === '"') {
                $quote = $char;
                continue;
            }
            if ($char === '(') $paren++;
            elseif ($char === ')') $paren--;
            elseif ($char === '[') $bracket++;
            elseif ($char === ']') $bracket--;
            elseif ($char === '{') $brace++;
            elseif ($char === '}') $brace--;
            elseif ($char === ',' && $paren === 0 && $bracket === 0 && $brace === 0) {
                $parts[] = trim(substr($source, $start, $i - $start));
                $start = $i + 1;
            }
        }

        $parts[] = trim(substr($source, $start));

        return $parts;
    }

    private function literalString(string $expression): ?string
    {
        $expression = trim($expression);
        if (preg_match('/^([\'\"])(.*)\\1$/s', $expression, $match) !== 1) {
            return null;
        }

        return stripcslashes($match[2]);
    }

    /** @param list<array{id:int|null,text:string,line:int,offset:int}> $tokens */
    private function offsetIsNonCode(array $tokens, int $offset): bool
    {
        foreach ($tokens as $token) {
            if ($token['offset'] > $offset) {
                break;
            }
            $end = $token['offset'] + strlen($token['text']);
            if ($offset >= $token['offset'] && $offset < $end) {
                return $token['id'] !== null && in_array($token['id'], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true);
            }
        }

        return false;
    }

    /** @param list<array{id:int|null,text:string,line:int,offset:int}> $tokens */
    private function tokenIndexAtOrAfterOffset(array $tokens, int $offset, string $text): ?int
    {
        foreach ($tokens as $index => $token) {
            if ($token['offset'] < $offset) {
                continue;
            }
            if ($token['text'] === $text) {
                return $index;
            }
        }

        return null;
    }

    /** @param list<array{id:int|null,text:string,line:int,offset:int}> $tokens */
    private function matchingDelimiter(array $tokens, int $open, string $openText, string $closeText): ?int
    {
        $depth = 0;
        $count = count($tokens);
        for ($i = $open; $i < $count; $i++) {
            if ($tokens[$i]['text'] === $openText) {
                $depth++;
            } elseif ($tokens[$i]['text'] === $closeText) {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    private function resolveInheritedInterfaces(): void
    {
        foreach (array_keys($this->classes) as $key) {
            $interfaces = $this->inheritedInterfacesFor($key, []);
            $metadata = $this->classes[$key];

            $this->classes[$key] = new ClassMetadata(
                name: $metadata->name,
                interfaces: $interfaces,
                parent: $metadata->parent,
                constructorAfterCommit: $metadata->constructorAfterCommit,
                constructorBeforeCommit: $metadata->constructorBeforeCommit,
                constructorQueueConnection: $metadata->constructorQueueConnection,
            );
        }
    }

    /** @param array<string, true> $seen @return list<string> */
    private function inheritedInterfacesFor(string $key, array $seen): array
    {
        if (isset($seen[$key]) || ! isset($this->classes[$key])) {
            return [];
        }
        $seen[$key] = true;
        $metadata = $this->classes[$key];
        $interfaces = $metadata->interfaces;

        if ($metadata->parent !== null) {
            $parentKey = strtolower(ltrim($metadata->parent, '\\'));
            $interfaces = array_merge($interfaces, $this->inheritedInterfacesFor($parentKey, $seen));
        }

        return array_values(array_unique($interfaces));
    }

    /** @return list<array{id:int|null,text:string,line:int,offset:int}> */
    private function tokens(string $source): array
    {
        $raw = token_get_all($source);
        $tokens = [];
        $offset = 0;
        $line = 1;

        foreach ($raw as $token) {
            if (is_array($token)) {
                [$id, $text, $tokenLine] = $token;
                $line = $tokenLine;
            } else {
                $id = null;
                $text = $token;
            }

            $tokens[] = ['id' => $id, 'text' => $text, 'line' => $line, 'offset' => $offset];
            $offset += strlen($text);
            $line += substr_count($text, "\n");
        }

        return $tokens;
    }

    private function isNameToken(?int $id): bool
    {
        return in_array($id, array_filter([
            T_STRING,
            defined('T_NAME_QUALIFIED') ? T_NAME_QUALIFIED : null,
            defined('T_NAME_FULLY_QUALIFIED') ? T_NAME_FULLY_QUALIFIED : null,
            defined('T_NS_SEPARATOR') ? T_NS_SEPARATOR : null,
        ]), true);
    }

    /** @param list<array{id:int|null,text:string,line:int,offset:int}> $tokens */
    private function previousSignificant(array $tokens, int $index): ?int
    {
        for ($i = $index; $i >= 0; $i--) {
            if (! in_array($tokens[$i]['id'] ?? null, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $i;
            }
        }

        return null;
    }

    /** @param list<array{id:int|null,text:string,line:int,offset:int}> $tokens */
    private function nextTokenOfType(array $tokens, int $start, int $type, ?int $end = null): ?int
    {
        $end ??= count($tokens) - 1;
        for ($i = $start; $i <= $end; $i++) {
            if (($tokens[$i]['id'] ?? null) === $type) {
                return $i;
            }
            if (! in_array($tokens[$i]['id'] ?? null, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG, T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG], true)
                && $tokens[$i]['text'] !== '&') {
                if ($type === T_STRING) {
                    return null;
                }
            }
        }

        return null;
    }

    /** @param list<array{id:int|null,text:string,line:int,offset:int}> $tokens */
    private function nextText(array $tokens, int $start, string $text, ?int $end = null): ?int
    {
        $end ??= count($tokens) - 1;
        for ($i = $start; $i <= $end; $i++) {
            if ($tokens[$i]['text'] === $text) {
                return $i;
            }
        }

        return null;
    }

    /** @param list<array{id:int|null,text:string,line:int,offset:int}> $tokens */
    private function matchingBrace(array $tokens, int $open, ?int $limit = null): ?int
    {
        $depth = 0;
        $limit ??= count($tokens) - 1;

        for ($i = $open; $i <= $limit; $i++) {
            if ($tokens[$i]['text'] === '{') {
                $depth++;
            } elseif ($tokens[$i]['text'] === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }
}
