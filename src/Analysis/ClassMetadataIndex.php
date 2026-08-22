<?php

declare(strict_types=1);

namespace Codegenie\TransactionGuard\Analysis;

/**
 * @phpstan-type Token array{id:int|null,text:string,line:int,offset:int}
 */
final class ClassMetadataIndex
{
    /** @var array<string, ClassMetadata> */
    private array $classes = [];

    /** @var array<string, FileContextMap> */
    private array $contexts = [];

    /** @var array<string, string> Known connection name or @dynamic when a route connection is not statically resolvable. */
    private array $queueRouteConnections = [];

    /** @var array<string, string> Queue name to forwarded connection. */
    private array $queueForwards = [];

    /** @var array<string, array<string, string>> */
    private array $notificationChannelConnections = [];

    /** @var array<string, string> */
    private array $modelConnections = [];

    /** @var array<string, array<string, string>> */
    private array $modelRelations = [];

    /** @var array<string, list<string>> */
    private array $interfaceParents = [];

    /** @var array<string, true> */
    private array $indexedFiles = [];

    /** @var array<string, true> */
    private array $indexingClasses = [];

    /** @var array<string, string> */
    private array $enumCaseValues = [];

    /** @param  list<string>  $files */
    public static function fromFiles(array $files): self
    {
        $index = new self;

        foreach ($files as $file) {
            $index->indexFile($file);
        }

        $index->resolveInheritedInterfaces();
        $index->resolveInheritedConstructorBehavior();

        return $index;
    }

    public function metadata(string $class): ?ClassMetadata
    {
        $key = strtolower(ltrim($class, '\\'));
        if (! isset($this->classes[$key])) {
            $this->ensureClassIndexed($class);
        }

        return $this->classes[$key] ?? null;
    }

    public function contextFor(string $file, int $offset = 0): FileContext
    {
        return isset($this->contexts[$file])
            ? $this->contexts[$file]->at($offset)
            : new FileContext('', []);
    }

    /** @return list<FileContext> */
    public function contextsFor(string $file): array
    {
        return isset($this->contexts[$file]) ? $this->contexts[$file]->contexts() : [new FileContext('', [])];
    }

    public function queueConnection(string $class, ?string $instanceConnection = null): ?string
    {
        $attribute = $this->queueConnectionAttributeFor($class);
        if ($attribute !== null) {
            return $attribute;
        }
        if ($instanceConnection !== null) {
            return $instanceConnection;
        }

        return $this->queueConfiguredConnectionFor($class) ?? $this->queueRouteConnection($class);
    }

    /** @return array<string, string> */
    public function notificationChannelConnections(string $class): array
    {
        $key = strtolower(ltrim($class, '\\'));
        if (array_key_exists($key, $this->notificationChannelConnections)) {
            return $this->notificationChannelConnections[$key];
        }

        $metadata = $this->metadata($class);
        if ($metadata?->parent === null) {
            return [];
        }

        return $this->notificationChannelConnections($metadata->parent);
    }

    public function modelConnection(string $class): ?string
    {
        $key = strtolower(ltrim($class, '\\'));
        if (array_key_exists($key, $this->modelConnections)) {
            return $this->modelConnections[$key];
        }

        $metadata = $this->metadata($class);
        if ($metadata?->parent === null) {
            return null;
        }

        return $this->modelConnection($metadata->parent);
    }

    public function modelRelationTarget(string $class, string $relation): ?string
    {
        $key = strtolower(ltrim($class, '\\'));
        $relation = strtolower($relation);
        if (isset($this->modelRelations[$key][$relation])) {
            return $this->modelRelations[$key][$relation];
        }

        $metadata = $this->metadata($class);

        return $metadata?->parent !== null ? $this->modelRelationTarget($metadata->parent, $relation) : null;
    }

    public function isDispatchableEvent(string $class): bool
    {
        $metadata = $this->metadata($class);
        if ($metadata === null) {
            return false;
        }
        if ($metadata->eventAfterCommit() || $metadata->usesEventDispatchableTrait()) {
            return true;
        }

        foreach ($this->traitsForClass($class) as $trait) {
            if (strcasecmp(ltrim($trait, '\\'), 'Illuminate\Foundation\Events\Dispatchable') === 0) {
                return true;
            }
        }

        return false;
    }

    public function isEloquentModel(string $class): bool
    {
        $seen = [];
        $current = $this->metadata($class);

        while ($current?->parent !== null) {
            $parent = ltrim($current->parent, '\\');
            $key = strtolower($parent);
            if ($key === 'illuminate\\database\\eloquent\\model') {
                return true;
            }
            if (isset($seen[$key])) {
                return false;
            }
            $seen[$key] = true;
            $current = $this->metadata($parent);
        }

        return false;
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
        $real = realpath($file) ?: $file;
        if (isset($this->indexedFiles[$real])) {
            return;
        }
        $this->indexedFiles[$real] = true;

        $source = @file_get_contents($file);
        if ($source === false) {
            return;
        }

        $tokens = $this->tokens($source);
        $contexts = FileContextMap::fromTokens($tokens, strlen($source));
        $this->contexts[$file] = $contexts;

        $this->indexInterfaceDeclarations($tokens, $contexts);
        $this->indexEnumDeclarations($tokens, $contexts);
        $this->indexQueueRoutes($source, $tokens, $contexts);
        $this->indexQueueForwards($source, $tokens, $contexts);
        $this->indexClassAndTraitDeclarations($source, $tokens, $contexts);
    }

    /**
     * @param  list<Token>  $tokens
     */
    private function indexClassAndTraitDeclarations(string $source, array $tokens, FileContextMap $contexts): void
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

            $context = $contexts->at($tokens[$i]['offset']);
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
            [$afterCommit, $beforeCommit, $constructorConnection, $constructorQueue, $constructorOverride, $declaresConstructor] =
                $this->constructorQueueBehavior($tokens, $openBrace + 1, $closeBrace - 1, $source);
            [$propertyConnection, $propertyQueue, $propertyAfterCommit] =
                $this->classQueueDefaults($tokens, $openBrace + 1, $closeBrace - 1, $source);

            $attributeQueue = $this->queueAttributeForDeclaration($source, $tokens[$i]['offset'], $context);
            $attributeConnection = $this->connectionAttributeForDeclaration($source, $tokens[$i]['offset'], $context);
            $debounced = MetadataAttributeResolver::hasClassAttribute(
                $source,
                $tokens[$i]['offset'],
                $context,
                'Illuminate\Queue\Attributes\DebounceFor',
                'DebounceFor',
            );
            $queueConnection = $constructorConnection ?? $propertyConnection;
            $queueName = $constructorQueue ?? $attributeQueue ?? $propertyQueue;
            $afterCommitOverride = $constructorOverride ?? $propertyAfterCommit;
            $fqcn = $context->namespace !== '' ? $context->namespace.'\\'.$name : $name;
            $notificationConnections = $this->notificationConnectionsForClass($tokens, $openBrace + 1, $closeBrace - 1, $source);
            if ($notificationConnections !== null) {
                $this->notificationChannelConnections[strtolower($fqcn)] = $notificationConnections;
            }
            $modelConnection = $this->modelConnectionForClass($source, $tokens, $i, $openBrace + 1, $closeBrace - 1, $context);
            if ($modelConnection !== null) {
                $this->modelConnections[strtolower($fqcn)] = $modelConnection;
            }
            $relations = ModelRelationExtractor::extract(
                $source,
                $tokens[$openBrace]['offset'] + 1,
                $tokens[$closeBrace]['offset'],
                $context,
            );
            if ($relations !== []) {
                $this->modelRelations[strtolower($fqcn)] = $relations;
            }

            $this->classes[strtolower($fqcn)] = new ClassMetadata(
                name: $fqcn,
                interfaces: array_values(array_unique($interfaces)),
                parent: $parent,
                constructorAfterCommit: $afterCommit,
                constructorBeforeCommit: $beforeCommit,
                constructorQueueConnection: $queueConnection,
                declaresConstructor: $declaresConstructor,
                queueConnectionAttribute: $attributeConnection,
                traits: $traits,
                queueName: $queueName,
                afterCommitOverride: $afterCommitOverride,
                debounced: $debounced,
            );

            $i = $closeBrace;
        }
    }

    /**
     * @param  list<Token>  $tokens
     */
    private function indexInterfaceDeclarations(array $tokens, FileContextMap $contexts): void
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

            $context = $contexts->at($tokens[$i]['offset']);
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
     * @return array<string, string>|null
     */
    private function notificationConnectionsForClass(array $tokens, int $start, int $end, string $source): ?array
    {
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
            if ($depth !== 0 || ($tokens[$i]['id'] ?? null) !== T_FUNCTION) {
                continue;
            }

            $nameIndex = $this->nextTokenOfType($tokens, $i + 1, T_STRING, $end);
            if ($nameIndex === null || strcasecmp($tokens[$nameIndex]['text'], 'viaConnections') !== 0) {
                continue;
            }

            $open = $this->nextText($tokens, $nameIndex + 1, '{', $end);
            if ($open === null) {
                return ['@dynamic' => '@dynamic'];
            }
            $close = $this->matchingBrace($tokens, $open, $end);
            if ($close === null) {
                return ['@dynamic' => '@dynamic'];
            }

            $bodyStart = $tokens[$open]['offset'] + 1;
            $body = substr($source, $bodyStart, max(0, $tokens[$close]['offset'] - $bodyStart));
            if (preg_match('/\breturn\s*\[(?<items>.*?)\]\s*;/s', $body, $match) !== 1) {
                return ['@dynamic' => '@dynamic'];
            }

            $result = [];
            foreach ($this->splitTopLevelArguments($match['items']) as $entry) {
                $parts = preg_split('/\s*=>\s*/', $entry, 2);
                if (! is_array($parts) || count($parts) !== 2) {
                    continue;
                }
                $channel = $this->literalString(trim($parts[0]));
                if ($channel === null) {
                    continue;
                }
                $result[$channel] = $this->literalString(trim($parts[1])) ?? '@dynamic';
            }

            return $result;
        }

        return null;
    }

    /** @param list<Token> $tokens */
    private function modelConnectionForClass(
        string $source,
        array $tokens,
        int $declarationIndex,
        int $start,
        int $end,
        FileContext $context,
    ): ?string {
        $attribute = $this->stringAttributeForDeclaration(
            $source,
            $tokens[$declarationIndex]['offset'],
            $context,
            'Illuminate\\Database\\Eloquent\\Attributes\\Connection',
            'connection',
        );
        if ($attribute !== null) {
            return $attribute;
        }

        if ($start > $end || ! isset($tokens[$start], $tokens[$end])) {
            return null;
        }
        $from = $tokens[$start]['offset'];
        $to = $tokens[$end]['offset'] + strlen($tokens[$end]['text']);
        $body = substr($source, $from, max(0, $to - $from));
        if (preg_match('/\b(?:public|protected)\b(?:(?![;{]).)*?\$connection\s*=\s*([^;]+);/is', $body, $match) !== 1) {
            return null;
        }

        $expression = trim($match[1]);
        if (strcasecmp($expression, 'null') === 0) {
            return null;
        }

        return $this->literalStringOrEnum($expression, $context) ?? '@dynamic';
    }

    /**
     * @param  list<Token>  $tokens
     * @return array{bool,bool,string|null,string|null,bool|null,bool}
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
                return [false, false, null, null, null, true];
            }

            $closeBrace = $this->matchingBrace($tokens, $openBrace, $end);
            if ($closeBrace === null) {
                return [false, false, null, null, null, true];
            }

            $body = $this->topLevelTokenSource($tokens, $openBrace + 1, $closeBrace - 1, $source);
            $afterMatches = $this->booleanQueuePreferenceMatches($body);
            $afterCommit = preg_match('/\$this\s*->\s*afterCommit\s*\(/', $body) === 1;
            $beforeCommit = preg_match('/\$this\s*->\s*beforeCommit\s*\(/', $body) === 1;
            $override = $afterMatches === [] ? null : end($afterMatches)['value'];

            return [
                $afterCommit,
                $beforeCommit,
                $this->lastQueueStringSetting($body, 'connection', 'onConnection'),
                $this->lastQueueStringSetting($body, 'queue', 'onQueue'),
                $override,
                true,
            ];
        }

        return [false, false, null, null, null, false];
    }

    /** @param list<Token> $tokens */
    private function topLevelTokenSource(array $tokens, int $start, int $end, string $source): string
    {
        if ($start > $end || ! isset($tokens[$start], $tokens[$end])) {
            return '';
        }

        $from = $tokens[$start]['offset'];
        $to = $tokens[$end]['offset'] + strlen($tokens[$end]['text']);
        $body = substr($source, $from, max(0, $to - $from));
        $depth = 0;

        for ($i = $start; $i <= $end; $i++) {
            $token = $tokens[$i];
            if ($token['text'] === '{') {
                $depth++;

                continue;
            }
            if ($token['text'] === '}') {
                $depth = max(0, $depth - 1);

                continue;
            }
            if ($depth === 0) {
                continue;
            }

            $relative = $token['offset'] - $from;
            $masked = preg_replace('/[^\r\n]/', ' ', $token['text']) ?? str_repeat(' ', strlen($token['text']));
            $body = substr_replace($body, $masked, $relative, strlen($token['text']));
        }

        return $body;
    }

    /**
     * @return list<array{offset:int,value:bool}>
     */
    private function booleanQueuePreferenceMatches(string $body): array
    {
        $matches = [];

        foreach ([
            '/\$this\s*->\s*afterCommit\s*\(/' => true,
            '/\$this\s*->\s*beforeCommit\s*\(/' => false,
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
        return $this->stringAttributeForDeclaration(
            $source,
            $declarationOffset,
            $context,
            'Illuminate\\Queue\\Attributes\\Queue',
            'queue',
        );
    }

    private function connectionAttributeForDeclaration(string $source, int $declarationOffset, FileContext $context): ?string
    {
        return $this->stringAttributeForDeclaration(
            $source,
            $declarationOffset,
            $context,
            'Illuminate\\Queue\\Attributes\\Connection',
            'connection',
        );
    }

    private function stringAttributeForDeclaration(
        string $source,
        int $declarationOffset,
        FileContext $context,
        string $attributeClass,
        string $argumentName,
    ): ?string {
        $prefixStart = max(0, $declarationOffset - 1500);
        $prefix = substr($source, $prefixStart, $declarationOffset - $prefixStart);
        if (preg_match('/(?<blocks>(?:#\[[^\]]+\]\s*)+)(?:(?:abstract|final|readonly)\s+)*$/s', $prefix, $match) !== 1) {
            return null;
        }

        $aliases = ['\\'.ltrim($attributeClass, '\\')];
        foreach ($context->imports as $alias => $import) {
            if (strcasecmp(ltrim($import, '\\'), ltrim($attributeClass, '\\')) === 0) {
                $aliases[] = $alias;
            }
        }

        $blockCount = preg_match_all('/#\[(?<attributes>[^\]]+)\]/s', $match['blocks'], $blocks, PREG_SET_ORDER);
        if ($blockCount === false || $blockCount === 0) {
            return null;
        }

        foreach ($blocks as $block) {
            foreach (array_values(array_unique($aliases)) as $alias) {
                $name = preg_quote($alias, '/');
                if (preg_match('/(?:^|,)\s*'.$name.'\s*\(\s*\)/s', $block['attributes']) === 1) {
                    return null;
                }

                $expressionPattern = '/(?:^|,)\s*'.$name.'\s*\(\s*(?:'.preg_quote($argumentName, '/').'\s*:\s*)?(?<expression>[^,)]+)\s*\)/s';
                if (preg_match($expressionPattern, $block['attributes'], $attribute) === 1) {
                    return $this->literalStringOrEnum(trim($attribute['expression']), $context) ?? '@dynamic';
                }
            }
        }

        return null;
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
    private function indexQueueRoutes(string $source, array $tokens, FileContextMap $contexts): void
    {
        foreach ($this->facadeAliasesForMap($contexts, 'Illuminate\\Support\\Facades\\Queue', 'Queue') as $alias) {
            $pattern = '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::\s*route\s*\(/i';
            $ok = preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE);
            if ($ok === false || $ok === 0) {
                continue;
            }

            foreach ($matches[0] as [$matched, $offset]) {
                $context = $contexts->at($offset);
                if (! in_array($alias, $this->facadeAliases($context, 'Illuminate\\Support\\Facades\\Queue', 'Queue'), true)
                    || $this->offsetIsNonCode($tokens, $offset)) {
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
                    $this->literalStringOrEnum($connectionExpression, $context) ?? '@dynamic';
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
                $connection = $this->literalStringOrEnum($value, $context) ?? '@dynamic';
                break;
            }
        }

        if (! $connectionSpecified && isset($parts[2])) {
            $connectionSpecified = true;
            $value = trim($parts[2]);
            if (strcasecmp($value, 'null') === 0) {
                return;
            }
            $connection = $this->literalStringOrEnum($value, $context) ?? '@dynamic';
        }

        if ($connectionSpecified && $connection !== null) {
            $this->queueRouteConnections[strtolower(ltrim($target, '\\'))] = $connection;
        }
    }

    /**
     * @param  list<Token>  $tokens
     */
    private function indexQueueForwards(string $source, array $tokens, FileContextMap $contexts): void
    {
        foreach ($this->facadeAliasesForMap($contexts, 'Illuminate\\Support\\Facades\\Queue', 'Queue') as $alias) {
            $pattern = '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::\s*forward\s*\(/i';
            $ok = preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE);
            if ($ok === false || $ok === 0) {
                continue;
            }

            foreach ($matches[0] as [$matched, $offset]) {
                $context = $contexts->at($offset);
                if (! in_array($alias, $this->facadeAliases($context, 'Illuminate\\Support\\Facades\\Queue', 'Queue'), true)
                    || $this->offsetIsNonCode($tokens, $offset)) {
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
                $this->parseQueueForwardArguments($arguments, $context);
            }
        }
    }

    private function parseQueueForwardArguments(string $arguments, FileContext $context): void
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
                    $connection = $this->literalStringOrEnum($value, $context) ?? '@dynamic';
                }
                break;
            }
        }

        if ($connection === null && isset($parts[2])) {
            $value = trim($parts[2]);
            if (strcasecmp($value, 'null') !== 0) {
                $connection = $this->literalStringOrEnum($value, $context) ?? '@dynamic';
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
    private function facadeAliasesForMap(FileContextMap $contexts, string $fqcn, string $fallback): array
    {
        $aliases = [];
        foreach ($contexts->contexts() as $context) {
            $aliases = array_merge($aliases, $this->facadeAliases($context, $fqcn, $fallback));
        }

        return array_values(array_unique($aliases));
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

    private function ensureClassIndexed(string $class): void
    {
        $class = ltrim($class, '\\');
        $key = strtolower($class);
        if (isset($this->classes[$key], $this->indexingClasses[$key])) {
            return;
        }
        $this->indexingClasses[$key] = true;

        try {
            foreach (spl_autoload_functions() ?: [] as $autoload) {
                $loader = is_array($autoload) ? $autoload[0] : null;
                if (! is_object($loader) || ! method_exists($loader, 'findFile')) {
                    continue;
                }
                $file = $loader->findFile($class);
                if (is_string($file) && $file !== '' && is_file($file)) {
                    $this->indexFile($file);
                    break;
                }
            }
        } finally {
            unset($this->indexingClasses[$key]);
        }
    }

    /** @param list<Token> $tokens */
    private function indexEnumDeclarations(array $tokens, FileContextMap $contexts): void
    {
        if (! defined('T_ENUM')) {
            return;
        }
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if (($tokens[$i]['id'] ?? null) !== T_ENUM) {
                continue;
            }
            $nameIndex = $this->nextTokenOfType($tokens, $i + 1, T_STRING);
            $context = $contexts->at($tokens[$i]['offset']);
            $open = $nameIndex === null ? null : $this->nextText($tokens, $nameIndex + 1, '{');
            $close = $open === null ? null : $this->matchingBrace($tokens, $open);
            if ($nameIndex === null || $open === null || $close === null) {
                continue;
            }
            $enum = $context->namespace !== '' ? $context->namespace.'\\'.$tokens[$nameIndex]['text'] : $tokens[$nameIndex]['text'];
            for ($j = $open + 1; $j < $close; $j++) {
                if (($tokens[$j]['id'] ?? null) !== T_CASE) {
                    continue;
                }
                $caseIndex = $this->nextTokenOfType($tokens, $j + 1, T_STRING, $close);
                if ($caseIndex === null) {
                    continue;
                }
                $equals = $this->nextText($tokens, $caseIndex + 1, '=', $close);
                $valueIndex = $equals === null ? null : $this->nextSignificant($tokens, $equals + 1, $close);
                if ($valueIndex === null || ($tokens[$valueIndex]['id'] ?? null) !== T_CONSTANT_ENCAPSED_STRING) {
                    continue;
                }
                $value = $this->literalString($tokens[$valueIndex]['text']);
                if ($value !== null) {
                    $this->enumCaseValues[strtolower($enum.'::'.$tokens[$caseIndex]['text'])] = $value;
                }
            }
        }
    }

    private function literalStringOrEnum(string $expression, FileContext $context): ?string
    {
        $literal = $this->literalString($expression);
        if ($literal !== null) {
            return $literal;
        }
        if (preg_match('/^(?<class>\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*)::(?<case>[A-Za-z_][A-Za-z0-9_]*)$/', trim($expression), $match) !== 1) {
            return null;
        }
        $class = $context->resolve($match['class']);
        $this->ensureClassIndexed($class);

        return $this->enumCaseValues[strtolower($class.'::'.$match['case'])] ?? null;
    }

    /** @param list<Token> $tokens */
    private function nextSignificant(array $tokens, int $start, ?int $end = null): ?int
    {
        $end ??= count($tokens) - 1;
        for ($i = $start; $i <= $end; $i++) {
            if (! in_array($tokens[$i]['id'] ?? null, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $i;
            }
        }

        return null;
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
                declaresConstructor: $metadata->declaresConstructor,
                queueConnectionAttribute: $metadata->queueConnectionAttribute,
                traits: $metadata->traits,
                queueName: $metadata->queueName,
                afterCommitOverride: $metadata->afterCommitOverride,
                debounced: $metadata->debounced,
            );
        }
    }

    private function resolveInheritedConstructorBehavior(): void
    {
        foreach (array_keys($this->classes) as $key) {
            $this->inheritConstructorBehaviorFor($key, []);
        }
    }

    /** @param array<string, true> $seen */
    private function inheritConstructorBehaviorFor(string $key, array $seen): void
    {
        if (isset($seen[$key]) || ! isset($this->classes[$key])) {
            return;
        }
        $seen[$key] = true;
        $metadata = $this->classes[$key];
        if ($metadata->declaresConstructor || $metadata->parent === null) {
            return;
        }

        $parentKey = strtolower(ltrim($metadata->parent, '\\'));
        $this->inheritConstructorBehaviorFor($parentKey, $seen);
        $parent = $this->classes[$parentKey] ?? null;
        if ($parent === null) {
            return;
        }

        $this->classes[$key] = new ClassMetadata(
            name: $metadata->name,
            interfaces: $metadata->interfaces,
            parent: $metadata->parent,
            constructorAfterCommit: $parent->constructorAfterCommit,
            constructorBeforeCommit: $parent->constructorBeforeCommit,
            constructorQueueConnection: $metadata->constructorQueueConnection ?? $parent->constructorQueueConnection,
            declaresConstructor: false,
            queueConnectionAttribute: $metadata->queueConnectionAttribute,
            traits: $metadata->traits,
            queueName: $metadata->queueName,
            afterCommitOverride: $metadata->afterCommitOverride ?? $parent->afterCommitOverride,
            debounced: $metadata->debounced || $parent->debounced,
        );
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

    /** @param  array<string, true>  $seen */
    /** @param array<string, true> $seen */
    private function queueConnectionAttributeFor(string $class, array $seen = []): ?string
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
        if ($metadata->queueConnectionAttribute !== null) {
            return $metadata->queueConnectionAttribute;
        }

        return $metadata->parent !== null ? $this->queueConnectionAttributeFor($metadata->parent, $seen) : null;
    }

    /** @param array<string, true> $seen */
    private function queueConfiguredConnectionFor(string $class, array $seen = []): ?string
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
        if ($metadata->constructorQueueConnection !== null) {
            return $metadata->constructorQueueConnection;
        }

        return $metadata->parent !== null ? $this->queueConfiguredConnectionFor($metadata->parent, $seen) : null;
    }

    /** @param array<string, true> $seen */
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
                return $token['id'] !== null && in_array($token['id'], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true);
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
