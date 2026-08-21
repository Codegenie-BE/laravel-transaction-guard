from pathlib import Path


def write(path: str, content: str) -> None:
    Path(path).write_text(content)


def replace_once(path: str, old: str, new: str) -> None:
    file = Path(path)
    text = file.read_text()
    if old not in text:
        raise SystemExit(f'Expected fragment not found in {path}: {old[:120]!r}')
    file.write_text(text.replace(old, new, 1))


write('src/Analysis/AnalysisConfig.php', r'''<?php

declare(strict_types=1);

namespace Codegenie\TransactionGuard\Analysis;

final readonly class AnalysisConfig
{
    /**
     * @param  array<string, bool>  $queueAfterCommitByConnection
     * @param  list<string>  $customSideEffectPatterns
     * @param  list<string>  $disabledRules
     */
    public function __construct(
        public string $defaultQueueConnection = 'sync',
        public array $queueAfterCommitByConnection = [],
        public array $customSideEffectPatterns = [],
        public array $disabledRules = [],
        public bool $detectReadHttpCalls = false,
        public string $defaultDatabaseConnection = '@default',
    ) {
        foreach ($this->customSideEffectPatterns as $pattern) {
            set_error_handler(static fn (): bool => true);
            try {
                $valid = preg_match($pattern, '') !== false;
            } finally {
                restore_error_handler();
            }

            if (! $valid) {
                throw new \InvalidArgumentException("Invalid custom side-effect regular expression [{$pattern}].");
            }
        }
    }

    public function ruleEnabled(string $rule): bool
    {
        return ! in_array($rule, $this->disabledRules, true);
    }

    public function queueDispatchesAfterCommit(?string $connection = null): bool
    {
        $connection ??= $this->defaultQueueConnection;

        return $this->queueAfterCommitByConnection[$connection] ?? false;
    }
}
''')

write('src/Analysis/ClassMetadata.php', r'''<?php

declare(strict_types=1);

namespace Codegenie\TransactionGuard\Analysis;

final readonly class ClassMetadata
{
    /**
     * @param  list<string>  $interfaces
     * @param  list<string>  $traits
     */
    public function __construct(
        public string $name,
        public array $interfaces,
        public ?string $parent = null,
        public bool $constructorAfterCommit = false,
        public bool $constructorBeforeCommit = false,
        public ?string $constructorQueueConnection = null,
        public array $traits = [],
        public ?string $queueName = null,
        public ?bool $afterCommitOverride = null,
    ) {}

    public function implements(string $interface): bool
    {
        $needle = ltrim($interface, '\\');

        foreach ($this->interfaces as $implemented) {
            if (strcasecmp(ltrim($implemented, '\\'), $needle) === 0 || str_ends_with($implemented, '\\'.$needle)) {
                return true;
            }
        }

        return false;
    }

    public function queued(): bool
    {
        return $this->implements('Illuminate\\Contracts\\Queue\\ShouldQueue')
            || $this->implements('Illuminate\\Contracts\\Queue\\ShouldQueueAfterCommit');
    }

    public function explicitlyBeforeCommit(): bool
    {
        if ($this->afterCommitOverride !== null) {
            return $this->afterCommitOverride === false;
        }

        return $this->constructorBeforeCommit;
    }

    public function queueAfterCommit(): bool
    {
        if ($this->afterCommitOverride !== null) {
            return $this->afterCommitOverride;
        }

        if ($this->constructorBeforeCommit) {
            return false;
        }

        return $this->constructorAfterCommit
            || $this->implements('Illuminate\\Contracts\\Queue\\ShouldQueueAfterCommit');
    }

    public function eventAfterCommit(): bool
    {
        return $this->implements('Illuminate\\Contracts\\Events\\ShouldDispatchAfterCommit');
    }
}
''')

write('src/Analysis/ClassMetadataIndex.php', r'''<?php

declare(strict_types=1);

namespace Codegenie\TransactionGuard\Analysis;

/**
 * @phpstan-type Token array{id:int|null,text:string,line:int,offset:int}
 */
final class ClassMetadataIndex
{
    /** @var array<string, ClassMetadata> */
    private array $classes = [];

    /** @var array<string, FileContext> */
    private array $contexts = [];

    /** @var array<string, string> Known connection name or @dynamic when a route connection is not statically resolvable. */
    private array $queueRouteConnections = [];

    /** @var array<string, string> Queue name to forwarded connection. */
    private array $queueForwards = [];

    /** @var array<string, list<string>> */
    private array $interfaceParents = [];

    /** @param  list<string>  $files */
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

        $metadata = $this->metadata($class);
        if ($metadata === null) {
            return null;
        }

        // Laravel checks parent classes before interfaces and recursive traits.
        $seenParents = [];
        $current = $metadata;
        while ($current->parent !== null) {
            $parent = ltrim($current->parent, '\\');
            $key = strtolower($parent);
            if (isset($seenParents[$key])) {
                break;
            }
            $seenParents[$key] = true;

            if (array_key_exists($key, $this->queueRouteConnections)) {
                return $this->queueRouteConnections[$key];
            }

            $next = $this->metadata($parent);
            if ($next === null) {
                break;
            }
            $current = $next;
        }

        $interfaceRoutes = [];
        foreach ($metadata->interfaces as $interface) {
            $key = strtolower(ltrim($interface, '\\'));
            if (array_key_exists($key, $this->queueRouteConnections)) {
                $interfaceRoutes[] = $this->queueRouteConnections[$key];
            }
        }

        $resolvedInterfaces = array_values(array_unique($interfaceRoutes));
        if (count($resolvedInterfaces) === 1) {
            return $resolvedInterfaces[0];
        }
        if (count($resolvedInterfaces) > 1) {
            return '@dynamic';
        }

        $traitRoutes = [];
        foreach ($this->traitsForClass($class) as $trait) {
            $key = strtolower(ltrim($trait, '\\'));
            if (array_key_exists($key, $this->queueRouteConnections)) {
                $traitRoutes[] = $this->queueRouteConnections[$key];
            }
        }

        $resolvedTraits = array_values(array_unique($traitRoutes));
        if (count($resolvedTraits) === 1) {
            return $resolvedTraits[0];
        }
        if (count($resolvedTraits) > 1) {
            return '@dynamic';
        }

        $queue = $this->queueNameFor($class);
        if ($queue === '@dynamic') {
            return '@dynamic';
        }

        return $queue !== null ? ($this->queueForwards[$queue] ?? null) : null;
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

        $this->indexInterfaceDeclarations($tokens, $context);
        $this->indexQueueRoutes($source, $tokens, $context);
        $this->indexQueueForwards($source, $tokens, $context);
        $this->indexClassAndTraitDeclarations($source, $tokens, $context);
    }

    /**
     * @param  list<Token>  $tokens
     */
    private function indexClassAndTraitDeclarations(string $source, array $tokens, FileContext $context): void
    {
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $declarationType = $tokens[$i]['id'];
            if (! in_array($declarationType, [T_CLASS, T_TRAIT], true)) {
                continue;
            }

            if ($declarationType === T_CLASS) {
                $previous = $this->previousSignificant($tokens, $i - 1);
                if ($previous !== null && ($tokens[$previous]['id'] ?? null) === T_DOUBLE_COLON) {
                    continue;
                }
            }

            $nameIndex = $this->nextTokenOfType($tokens, $i + 1, T_STRING);
            if ($nameIndex === null) {
                continue;
            }

            $name = $tokens[$nameIndex]['text'];
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

                if ($declarationType === T_CLASS && ($token['id'] ?? null) === T_IMPLEMENTS) {
                    if ($mode === 'extends' && trim($buffer) !== '') {
                        $parent = $this->parseSingleName($buffer, $context);
                    }
                    $mode = 'implements';
                    $buffer = '';

                    continue;
                }

                if ($declarationType === T_CLASS && ($token['id'] ?? null) === T_EXTENDS) {
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

            $traits = $this->traitsUsed($tokens, $openBrace + 1, $closeBrace - 1, $context);
            [$afterCommit, $beforeCommit, $constructorConnection, $constructorQueue, $constructorOverride] =
                $this->constructorQueueBehavior($tokens, $openBrace + 1, $closeBrace - 1, $source);
            [$propertyConnection, $propertyQueue, $propertyAfterCommit] =
                $this->classQueueDefaults($tokens, $openBrace + 1, $closeBrace - 1, $source);

            $attributeQueue = $this->queueAttributeForDeclaration($source, $tokens[$i]['offset'], $context);
            $queueConnection = $constructorConnection ?? $propertyConnection;
            $queueName = $constructorQueue ?? $attributeQueue ?? $propertyQueue;
            $afterCommitOverride = $constructorOverride ?? $propertyAfterCommit;
            $fqcn = $context->namespace !== '' ? $context->namespace.'\\'.$name : $name;

            $this->classes[strtolower($fqcn)] = new ClassMetadata(
                name: $fqcn,
                interfaces: array_values(array_unique($interfaces)),
                parent: $parent,
                constructorAfterCommit: $afterCommit,
                constructorBeforeCommit: $beforeCommit,
                constructorQueueConnection: $queueConnection,
                traits: $traits,
                queueName: $queueName,
                afterCommitOverride: $afterCommitOverride,
            );

            $i = $closeBrace;
        }
    }

    /**
     * @param  list<Token>  $tokens
     */
    private function indexInterfaceDeclarations(array $tokens, FileContext $context): void
    {
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (($tokens[$i]['id'] ?? null) !== T_INTERFACE) {
                continue;
            }

            $nameIndex = $this->nextTokenOfType($tokens, $i + 1, T_STRING);
            if ($nameIndex === null) {
                continue;
            }

            $parents = [];
            $buffer = '';
            $collecting = false;

            for ($j = $nameIndex + 1; $j < $count; $j++) {
                if ($tokens[$j]['text'] === '{') {
                    if ($collecting && trim($buffer) !== '') {
                        $parents = $this->parseNameList($buffer, $context);
                    }
                    break;
                }

                if (($tokens[$j]['id'] ?? null) === T_EXTENDS) {
                    $collecting = true;
                    $buffer = '';

                    continue;
                }

                if ($collecting) {
                    $buffer .= $tokens[$j]['text'];
                }
            }

            $name = $tokens[$nameIndex]['text'];
            $fqcn = $context->namespace !== '' ? $context->namespace.'\\'.$name : $name;
            $this->interfaceParents[strtolower($fqcn)] = array_values(array_unique($parents));
        }
    }

    /**
     * @param  list<Token>  $tokens
     * @return list<string>
     */
    private function traitsUsed(array $tokens, int $start, int $end, FileContext $context): array
    {
        $traits = [];
        $depth = 0;

        for ($i = $start; $i <= $end; $i++) {
            $text = $tokens[$i]['text'];
            if ($text === '{') {
                $depth++;
                continue;
            }
            if ($text === '}') {
                $depth = max(0, $depth - 1);
                continue;
            }

            if (($tokens[$i]['id'] ?? null) !== T_USE || $depth !== 0) {
                continue;
            }

            $clause = '';
            for ($j = $i + 1; $j <= $end; $j++) {
                if (in_array($tokens[$j]['text'], [';', '{'], true)) {
                    $traits = array_merge($traits, $this->parseNameList($clause, $context));
                    if ($tokens[$j]['text'] === '{') {
                        $close = $this->matchingDelimiter($tokens, $j, '{', '}');
                        $i = $close ?? $j;
                    } else {
                        $i = $j;
                    }
                    break;
                }
                $clause .= $tokens[$j]['text'];
            }
        }

        return array_values(array_unique($traits));
    }

    /**
     * @param  list<Token>  $tokens
     * @return array{bool,bool,string|null,string|null,bool|null}
     */
    private function constructorQueueBehavior(array $tokens, int $start, int $end, string $source): array
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
                return [false, false, null, null, null];
            }

            $closeBrace = $this->matchingBrace($tokens, $openBrace, $end);
            if ($closeBrace === null) {
                return [false, false, null, null, null];
            }

            $offset = $tokens[$openBrace]['offset'];
            $length = ($tokens[$closeBrace]['offset'] + strlen($tokens[$closeBrace]['text'])) - $offset;
            $body = substr($source, $offset, $length);

            $afterMatches = $this->booleanQueuePreferenceMatches($body);
            $afterCommit = str_contains($body, '->afterCommit(');
            $beforeCommit = str_contains($body, '->beforeCommit(');
            $override = $afterMatches === [] ? null : end($afterMatches)['value'];

            return [
                $afterCommit,
                $beforeCommit,
                $this->lastQueueStringSetting($body, 'connection', 'onConnection'),
                $this->lastQueueStringSetting($body, 'queue', 'onQueue'),
                $override,
            ];
        }

        return [false, false, null, null, null];
    }

    /**
     * @return list<array{offset:int,value:bool}>
     */
    private function booleanQueuePreferenceMatches(string $body): array
    {
        $matches = [];

        foreach ([
            '/->\s*afterCommit\s*\(/' => true,
            '/->\s*beforeCommit\s*\(/' => false,
        ] as $pattern => $value) {
            if (preg_match_all($pattern, $body, $found, PREG_OFFSET_CAPTURE) > 0) {
                foreach ($found[0] as $match) {
                    $matches[] = ['offset' => $match[1], 'value' => $value];
                }
            }
        }

        if (preg_match_all('/\$this\s*->\s*afterCommit\s*=\s*(true|false)\b/i', $body, $found, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) > 0) {
            foreach ($found as $match) {
                $matches[] = [
                    'offset' => $match[0][1],
                    'value' => strtolower($match[1][0]) === 'true',
                ];
            }
        }

        usort($matches, static fn (array $a, array $b): int => $a['offset'] <=> $b['offset']);

        return $matches;
    }

    private function lastQueueStringSetting(string $body, string $property, string $method): ?string
    {
        $matches = [];

        if (preg_match_all('/\$this\s*->\s*'.preg_quote($method, '/').'\s*\((.*?)\)/s', $body, $found, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) > 0) {
            foreach ($found as $match) {
                $expression = trim($match[1][0]);
                $matches[] = [
                    'offset' => $match[0][1],
                    'value' => $this->literalString($expression) ?? '@dynamic',
                ];
            }
        }

        if (preg_match_all('/\$this\s*->\s*'.preg_quote($property, '/').'\s*=\s*([^;]+);/s', $body, $found, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) > 0) {
            foreach ($found as $match) {
                $expression = trim($match[1][0]);
                $matches[] = [
                    'offset' => $match[0][1],
                    'value' => $this->literalString($expression) ?? '@dynamic',
                ];
            }
        }

        if ($matches === []) {
            return null;
        }

        usort($matches, static fn (array $a, array $b): int => $a['offset'] <=> $b['offset']);

        return end($matches)['value'];
    }

    /**
     * @param  list<Token>  $tokens
     * @return array{string|null,string|null,bool|null}
     */
    private function classQueueDefaults(array $tokens, int $start, int $end, string $source): array
    {
        $bodyStart = $tokens[$start]['offset'] ?? 0;
        $bodyEnd = isset($tokens[$end]) ? $tokens[$end]['offset'] + strlen($tokens[$end]['text']) : $bodyStart;
        $body = substr($source, $bodyStart, max(0, $bodyEnd - $bodyStart));

        return [
            $this->publicStringProperty($body, 'connection'),
            $this->publicStringProperty($body, 'queue'),
            $this->publicBoolProperty($body, 'afterCommit'),
        ];
    }

    private function publicStringProperty(string $body, string $property): ?string
    {
        $pattern = '/\bpublic\b(?:(?![;{]).)*?\$'.preg_quote($property, '/').'\s*=\s*([^;]+);/is';
        if (preg_match($pattern, $body, $match) !== 1) {
            return null;
        }

        $expression = trim($match[1]);
        if (strcasecmp($expression, 'null') === 0) {
            return null;
        }

        return $this->literalString($expression) ?? '@dynamic';
    }

    private function publicBoolProperty(string $body, string $property): ?bool
    {
        $pattern = '/\bpublic\b(?:(?![;{]).)*?\$'.preg_quote($property, '/').'\s*=\s*(true|false)\s*;/is';
        if (preg_match($pattern, $body, $match) !== 1) {
            return null;
        }

        return strtolower($match[1]) === 'true';
    }

    private function queueAttributeForDeclaration(string $source, int $declarationOffset, FileContext $context): ?string
    {
        $prefixStart = max(0, $declarationOffset - 1500);
        $prefix = substr($source, $prefixStart, $declarationOffset - $prefixStart);
        if (preg_match('/#\[(?<attributes>[^\]]+)\]\s*(?:(?:abstract|final|readonly)\s+)*$/s', $prefix, $match) !== 1) {
            return null;
        }

        $aliases = ['\\Illuminate\\Queue\\Attributes\\Queue'];
        foreach ($context->imports as $alias => $import) {
            if (strcasecmp(ltrim($import, '\\'), 'Illuminate\\Queue\\Attributes\\Queue') === 0) {
                $aliases[] = $alias;
            }
        }

        foreach (array_values(array_unique($aliases)) as $alias) {
            $pattern = '/(?:^|,)\s*'.preg_quote($alias, '/').'\s*\(\s*([\'\"])(.*?)\1\s*\)/s';
            if (preg_match($pattern, $match['attributes'], $attribute) === 1) {
                return stripcslashes($attribute[2]);
            }
        }

        return null;
    }

    /**
     * @param  list<Token>  $tokens
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
                $namespace = trim($name, " \t\n\r\0\x0B\\");

                continue;
            }

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

        if (preg_match('/^(.+?)\\\{(.+)\}$/', $clause, $matches) === 1) {
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

    /** @param  array<string, string>  $result */
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
     * @param  list<Token>  $tokens
     */
    private function indexQueueRoutes(string $source, array $tokens, FileContext $context): void
    {
        foreach ($this->facadeAliases($context, 'Illuminate\\Support\\Facades\\Queue', 'Queue') as $alias) {
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
            if (preg_match_all('/(?P<class>\\?[A-Za-z_][A-Za-z0-9_\\]*)\s*::\s*class\s*=>\s*(?P<value>\[[^\]]*\]|[\'\"][^\'\"]*[\'\"])/s', $arguments, $entries, PREG_SET_ORDER) === false) {
                return;
            }

            foreach ($entries as $entry) {
                $target = $context->resolve($entry['class']);
                $value = trim($entry['value']);
                if (! str_starts_with($value, '[')) {
                    continue;
                }

                $parts = $this->splitTopLevelArguments(substr($value, 1, -1));
                if ($parts === []) {
                    continue;
                }
                $connectionExpression = trim($parts[0]);
                if (strcasecmp($connectionExpression, 'null') === 0) {
                    continue;
                }

                $this->queueRouteConnections[strtolower(ltrim($target, '\\'))] =
                    $this->literalString($connectionExpression) ?? '@dynamic';
            }

            return;
        }

        $parts = $this->splitTopLevelArguments($arguments);
        if ($parts === [] || preg_match('/^\s*(\\?[A-Za-z_][A-Za-z0-9_\\]*)\s*::\s*class\s*$/', $parts[0], $match) !== 1) {
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

    /**
     * @param  list<Token>  $tokens
     */
    private function indexQueueForwards(string $source, array $tokens, FileContext $context): void
    {
        foreach ($this->facadeAliases($context, 'Illuminate\\Support\\Facades\\Queue', 'Queue') as $alias) {
            $pattern = '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::\s*forward\s*\(/i';
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
                $arguments = substr($source, $argsStart, max(0, $tokens[$close]['offset'] - $argsStart));
                $this->parseQueueForwardArguments($arguments);
            }
        }
    }

    private function parseQueueForwardArguments(string $arguments): void
    {
        $parts = $this->splitTopLevelArguments($arguments);
        if ($parts === []) {
            return;
        }

        $connection = null;
        foreach (array_slice($parts, 1) as $part) {
            if (preg_match('/^\s*connection\s*:\s*(.+)$/is', $part, $named) === 1) {
                $value = trim($named[1]);
                if (strcasecmp($value, 'null') !== 0) {
                    $connection = $this->literalString($value) ?? '@dynamic';
                }
                break;
            }
        }

        if ($connection === null && isset($parts[2])) {
            $value = trim($parts[2]);
            if (strcasecmp($value, 'null') !== 0) {
                $connection = $this->literalString($value) ?? '@dynamic';
            }
        }

        if ($connection === null) {
            return;
        }

        $first = trim($parts[0]);
        if (str_starts_with($first, '[')) {
            if (preg_match_all('/([\'\"])(.*?)\1\s*=>/s', $first, $entries, PREG_SET_ORDER) > 0) {
                foreach ($entries as $entry) {
                    $this->queueForwards[stripcslashes($entry[2])] = $connection;
                }
            }

            return;
        }

        $queue = $this->literalString($first);
        if ($queue !== null) {
            $this->queueForwards[$queue] = $connection;
        }
    }

    /** @return list<string> */
    private function facadeAliases(FileContext $context, string $fqcn, string $fallback): array
    {
        $normalized = ltrim($fqcn, '\\');
        $aliases = ['\\'.$normalized];
        $fallbackImport = $context->imports[$fallback] ?? null;
        if ($fallbackImport === null || strcasecmp(ltrim($fallbackImport, '\\'), $normalized) === 0) {
            $aliases[] = $fallback;
        }

        foreach ($context->imports as $alias => $import) {
            if (strcasecmp(ltrim($import, '\\'), $normalized) === 0) {
                $aliases[] = $alias;
            }
        }

        return array_values(array_unique($aliases));
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
            if ($char === '(') {
                $paren++;
            } elseif ($char === ')') {
                $paren--;
            } elseif ($char === '[') {
                $bracket++;
            } elseif ($char === ']') {
                $bracket--;
            } elseif ($char === '{') {
                $brace++;
            } elseif ($char === '}') {
                $brace--;
            } elseif ($char === ',' && $paren === 0 && $bracket === 0 && $brace === 0) {
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
        if (preg_match('/^([\'\"])(.*)\1$/s', $expression, $match) !== 1) {
            return null;
        }

        return stripcslashes($match[2]);
    }

    private function resolveInheritedInterfaces(): void
    {
        foreach (array_keys($this->classes) as $key) {
            $interfaces = $this->inheritedInterfacesForClass($key, []);
            $metadata = $this->classes[$key];

            $this->classes[$key] = new ClassMetadata(
                name: $metadata->name,
                interfaces: $interfaces,
                parent: $metadata->parent,
                constructorAfterCommit: $metadata->constructorAfterCommit,
                constructorBeforeCommit: $metadata->constructorBeforeCommit,
                constructorQueueConnection: $metadata->constructorQueueConnection,
                traits: $metadata->traits,
                queueName: $metadata->queueName,
                afterCommitOverride: $metadata->afterCommitOverride,
            );
        }
    }

    /**
     * @param  array<string, true>  $seen
     * @return list<string>
     */
    private function inheritedInterfacesForClass(string $key, array $seen): array
    {
        if (isset($seen[$key]) || ! isset($this->classes[$key])) {
            return [];
        }
        $seen[$key] = true;
        $metadata = $this->classes[$key];
        $interfaces = [];

        foreach ($metadata->interfaces as $interface) {
            $interfaces = array_merge($interfaces, $this->expandedInterface($interface, []));
        }

        if ($metadata->parent !== null) {
            $parentKey = strtolower(ltrim($metadata->parent, '\\'));
            $interfaces = array_merge($interfaces, $this->inheritedInterfacesForClass($parentKey, $seen));
        }

        return array_values(array_unique($interfaces));
    }

    /**
     * @param  array<string, true>  $seen
     * @return list<string>
     */
    private function expandedInterface(string $interface, array $seen): array
    {
        $key = strtolower(ltrim($interface, '\\'));
        if (isset($seen[$key])) {
            return [];
        }
        $seen[$key] = true;
        $interfaces = [$interface];

        foreach ($this->interfaceParents[$key] ?? [] as $parent) {
            $interfaces = array_merge($interfaces, $this->expandedInterface($parent, $seen));
        }

        return array_values(array_unique($interfaces));
    }

    /** @return list<string> */
    private function traitsForClass(string $class): array
    {
        return $this->inheritedTraitsFor(strtolower(ltrim($class, '\\')), []);
    }

    /**
     * @param  array<string, true>  $seen
     * @return list<string>
     */
    private function inheritedTraitsFor(string $key, array $seen): array
    {
        if (isset($seen[$key]) || ! isset($this->classes[$key])) {
            return [];
        }
        $seen[$key] = true;
        $metadata = $this->classes[$key];
        $traits = [];

        foreach ($metadata->traits as $trait) {
            $traits[] = $trait;
            $traits = array_merge($traits, $this->inheritedTraitsFor(strtolower(ltrim($trait, '\\')), $seen));
        }

        if ($metadata->parent !== null) {
            $traits = array_merge($traits, $this->inheritedTraitsFor(strtolower(ltrim($metadata->parent, '\\')), $seen));
        }

        return array_values(array_unique($traits));
    }

    private function queueNameFor(string $class, array $seen = []): ?string
    {
        $key = strtolower(ltrim($class, '\\'));
        if (isset($seen[$key])) {
            return null;
        }
        $seen[$key] = true;
        $metadata = $this->classes[$key] ?? null;
        if ($metadata === null) {
            return null;
        }

        if ($metadata->queueName !== null) {
            return $metadata->queueName;
        }

        $traitQueues = [];
        foreach ($metadata->traits as $trait) {
            $queue = $this->queueNameFor($trait, $seen);
            if ($queue !== null) {
                $traitQueues[] = $queue;
            }
        }
        $traitQueues = array_values(array_unique($traitQueues));
        if (count($traitQueues) === 1) {
            return $traitQueues[0];
        }
        if (count($traitQueues) > 1) {
            return '@dynamic';
        }

        return $metadata->parent !== null ? $this->queueNameFor($metadata->parent, $seen) : null;
    }

    /**
     * @param  list<Token>  $tokens
     */
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

    /**
     * @param  list<Token>  $tokens
     */
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

    /**
     * @param  list<Token>  $tokens
     */
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

    /** @return list<Token> */
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

    /**
     * @param  list<Token>  $tokens
     */
    private function previousSignificant(array $tokens, int $index): ?int
    {
        for ($i = $index; $i >= 0; $i--) {
            if (! in_array($tokens[$i]['id'] ?? null, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param  list<Token>  $tokens
     */
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

    /**
     * @param  list<Token>  $tokens
     */
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

    /**
     * @param  list<Token>  $tokens
     */
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
''')

write('src/TransactionGuard.php', r'''<?php

declare(strict_types=1);

namespace Codegenie\TransactionGuard;

use Codegenie\TransactionGuard\Analysis\AnalysisConfig;
use Codegenie\TransactionGuard\Analysis\AnalysisResult;
use Codegenie\TransactionGuard\Analysis\Baseline;
use Codegenie\TransactionGuard\Analysis\ClassMetadataIndex;
use Codegenie\TransactionGuard\Analysis\Finding;
use Codegenie\TransactionGuard\Analysis\SourceScanner;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class TransactionGuard
{
    public function __construct(private readonly AnalysisConfig $config = new AnalysisConfig) {}

    /**
     * @param  list<string>  $paths
     * @param  list<string>  $excludePatterns
     */
    public function analyze(array $paths, array $excludePatterns = [], ?Baseline $baseline = null): AnalysisResult
    {
        $files = $this->discoverPhpFiles($paths, $excludePatterns);
        $index = ClassMetadataIndex::fromFiles($files);
        $scanner = new SourceScanner($index, $this->config);
        $findings = [];

        foreach ($files as $file) {
            foreach ($scanner->scan($file) as $finding) {
                if ($baseline?->contains($finding) === true) {
                    continue;
                }
                $findings[] = $finding;
            }
        }

        usort($findings, static fn (Finding $a, Finding $b): int => [str_replace('\\', '/', $a->file), $a->line, -$a->severity->value] <=> [str_replace('\\', '/', $b->file), $b->line, -$b->severity->value]);

        return new AnalysisResult($findings, count($files));
    }

    /**
     * @param  list<string>  $paths
     * @param  list<string>  $excludePatterns
     * @return list<string>
     */
    public function discoverPhpFiles(array $paths, array $excludePatterns = []): array
    {
        $files = [];

        foreach ($paths as $path) {
            if (is_file($path) && str_ends_with(strtolower($path), '.php')) {
                if (! $this->excluded($path, $excludePatterns)) {
                    $files[] = realpath($path) ?: $path;
                }
                continue;
            }

            if (! is_dir($path) || $this->excluded($path, $excludePatterns)) {
                continue;
            }

            $directory = new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS);
            $filter = new RecursiveCallbackFilterIterator(
                $directory,
                fn (SplFileInfo $entry): bool => ! $this->excluded($entry->getPathname(), $excludePatterns),
            );
            $iterator = new RecursiveIteratorIterator(
                $filter,
                RecursiveIteratorIterator::LEAVES_ONLY,
                RecursiveIteratorIterator::CATCH_GET_CHILD,
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (! $file->isFile() || strtolower($file->getExtension()) !== 'php') {
                    continue;
                }
                $filename = $file->getPathname();
                if (! $this->excluded($filename, $excludePatterns)) {
                    $files[] = realpath($filename) ?: $filename;
                }
            }
        }

        $files = array_values(array_unique($files));
        sort($files);

        return $files;
    }

    /** @param  list<string>  $patterns */
    private function excluded(string $path, array $patterns): bool
    {
        $normalized = trim(str_replace('\\', '/', $path), '/');
        $segments = explode('/', $normalized);

        foreach ($patterns as $pattern) {
            $pattern = trim(str_replace('\\', '/', trim($pattern)), '/');
            if ($pattern === '') {
                continue;
            }

            if (strpbrk($pattern, '*?[') !== false) {
                if (fnmatch($pattern, $normalized) || fnmatch('*/'.$pattern, $normalized) || fnmatch('*/'.$pattern.'/*', $normalized)) {
                    return true;
                }
                continue;
            }

            if (! str_contains($pattern, '/')) {
                if (in_array($pattern, $segments, true)) {
                    return true;
                }
                continue;
            }

            $haystack = '/'.$normalized.'/';
            $needle = '/'.$pattern.'/';
            if ($normalized === $pattern || str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
''')

replace_once(
    'src/Console/TransactionGuardCommand.php',
    "                detectReadHttpCalls: (bool) config('transaction-guard.detect_read_http_calls', false),\n",
    "                detectReadHttpCalls: (bool) config('transaction-guard.detect_read_http_calls', false),\n                defaultDatabaseConnection: (string) config('database.default', '@default'),\n",
)

# Remove the broad PHPStan baseline. The v0.2 pass must earn a clean level-8 analysis.
phpstan = Path('phpstan.neon').read_text()
if '\n    ignoreErrors:' in phpstan:
    phpstan = phpstan.split('\n    # Transaction Guard deliberately performs tokenizer-based control-flow analysis.', 1)[0].rstrip() + '\n'
Path('phpstan.neon').write_text(phpstan)
