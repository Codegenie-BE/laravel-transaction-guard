from pathlib import Path


def replace_once(source: str, old: str, new: str, label: str) -> str:
    count = source.count(old)
    if count != 1:
        raise SystemExit(f"{label}: expected exactly one match, found {count}")
    return source.replace(old, new, 1)


scanner_path = Path('src/Analysis/SourceScanner.php')
source = scanner_path.read_text()

source = replace_once(
    source,
    "    /** @var array<string, list<string>> */\n    private array $facadeAliasCache = [];\n",
    "    /** @var array<string, list<string>> */\n    private array $facadeAliasCache = [];\n\n"
    "    /** @var array<string, list<array{fqcn:string,fallback:string}>> */\n"
    "    private array $facadeAliasTargets = [];\n",
    'facade alias target property',
)

source = replace_once(
    source,
    "        $this->facadeAliasCache = [];\n        $this->preScanFindings = [];\n",
    "        $this->facadeAliasCache = [];\n        $this->facadeAliasTargets = [];\n        $this->preScanFindings = [];\n",
    'facade alias target reset',
)

source = replace_once(
    source,
    "        foreach ($findings as $finding) {\n            if (! $this->config->ruleEnabled($finding->rule)) {\n",
    "        foreach ($findings as $finding) {\n"
    "            $finding = RedisFindingRefiner::refine($finding);\n"
    "            if ($finding === null) {\n"
    "                continue;\n"
    "            }\n"
    "            if (! $this->config->ruleEnabled($finding->rule)) {\n",
    'canonical finding refinement',
)

source = source.replace(
    '$resolved = $this->context->resolve($class);',
    '$resolved = $this->resolveClassAt($class, $offset);',
)
source = source.replace(
    '$this->classIndex->metadata($this->context->resolve($jobClass))',
    '$this->classIndex->metadata($this->resolveClassAt($jobClass, $offset))',
)
source = source.replace(
    '$this->classIndex->metadata($this->context->resolve($class))',
    '$this->classIndex->metadata($this->resolveClassAt($class, $offset))',
)
source = source.replace(
    "$resolved = $this->context->resolve($this->tokens[$name]['text']);",
    "$resolved = $this->resolveClassAt($this->tokens[$name]['text'], $this->tokens[$name]['offset']);",
)
if '$this->context->resolve(' in source:
    raise SystemExit('unconverted scanner-wide class resolution remains')

source = replace_once(
    source,
    "            if (! $this->capturedMethodIsTopLevel($match)) {\n"
    "                continue;\n"
    "            }\n"
    "            $result[] = ['offset' => $offset, 'matches' => $match];\n",
    "            if (! $this->capturedMethodIsTopLevel($match)) {\n"
    "                continue;\n"
    "            }\n"
    "            if (! $this->staticFacadeMatchUsesValidContext($pattern, $match, $offset)) {\n"
    "                continue;\n"
    "            }\n"
    "            $result[] = ['offset' => $offset, 'matches' => $match];\n",
    'static facade match validation',
)

start_marker = "    /** @return list<string> */\n    private function facadeAliases(string $fqcn, string $fallback): array\n"
end_marker = "    /** @param  list<Finding>  $findings */\n    private function appendExplicitBeforeCommitFinding"
start = source.find(start_marker)
end = source.find(end_marker, start)
if start < 0 or end < 0:
    raise SystemExit('facadeAliases block markers not found')

facade_block = r'''    /** @return list<string> */
    private function facadeAliases(string $fqcn, string $fallback): array
    {
        $cacheKey = strtolower(ltrim($fqcn, '\\')).'|'.$fallback;
        if (isset($this->facadeAliasCache[$cacheKey])) {
            return $this->facadeAliasCache[$cacheKey];
        }

        $normalized = ltrim($fqcn, '\\');
        $aliases = ['\\'.$normalized];
        foreach ($this->classIndex->contextsFor($this->file) as $context) {
            $fallbackImport = $context->importForAlias($fallback);
            if ($fallbackImport === null || strcasecmp(ltrim($fallbackImport, '\\'), $normalized) === 0) {
                $aliases[] = $fallback;
            }
            foreach ($context->imports as $alias => $import) {
                if (strcasecmp(ltrim($import, '\\'), $normalized) === 0) {
                    $aliases[] = $alias;
                }
            }
        }

        $aliases = array_values(array_unique($aliases));
        foreach ($aliases as $alias) {
            $this->registerFacadeAliasTarget($alias, $normalized, $fallback);
        }

        return $this->facadeAliasCache[$cacheKey] = $aliases;
    }

    private function registerFacadeAliasTarget(string $alias, string $fqcn, string $fallback): void
    {
        $key = strtolower(ltrim($alias, '\\'));
        foreach ($this->facadeAliasTargets[$key] ?? [] as $target) {
            if (strcasecmp($target['fqcn'], $fqcn) === 0 && strcasecmp($target['fallback'], $fallback) === 0) {
                return;
            }
        }

        $this->facadeAliasTargets[$key][] = ['fqcn' => $fqcn, 'fallback' => $fallback];
    }

    private function facadeAliasValidAt(string $alias, string $fqcn, string $fallback, int $offset): bool
    {
        return $this->facadeAliasValidInContext(
            $alias,
            $fqcn,
            $fallback,
            $this->classIndex->contextFor($this->file, $offset),
        );
    }

    private function facadeAliasValidInContext(string $alias, string $fqcn, string $fallback, FileContext $context): bool
    {
        $normalized = ltrim($fqcn, '\\');
        if (str_starts_with($alias, '\\')) {
            return strcasecmp(ltrim($alias, '\\'), $normalized) === 0;
        }

        $import = $context->importForAlias($alias);
        if ($import !== null) {
            return strcasecmp(ltrim($import, '\\'), $normalized) === 0;
        }

        return strcasecmp($alias, $fallback) === 0;
    }

    /** @param array<int|string, mixed> $match */
    private function staticFacadeMatchUsesValidContext(string $pattern, array $match, int $offset): bool
    {
        $full = $match[0] ?? null;
        if (! is_array($full) || ! isset($full[0]) || ! is_string($full[0])) {
            return true;
        }
        if (preg_match('/^\s*(?<alias>\\?[A-Za-z_][A-Za-z0-9_\\]*)\s*::/', $full[0], $aliasMatch) !== 1) {
            return true;
        }

        $alias = $aliasMatch['alias'];
        if (! str_contains($pattern, preg_quote($alias, '/'))) {
            return true;
        }

        $targets = $this->facadeAliasTargets[strtolower(ltrim($alias, '\\'))] ?? [];
        if ($targets === []) {
            return true;
        }

        foreach ($targets as $target) {
            if ($this->facadeAliasValidAt($alias, $target['fqcn'], $target['fallback'], $offset)) {
                return true;
            }
        }

        return false;
    }

    private function resolveClassAt(string $class, int $offset): string
    {
        return $this->classIndex->contextFor($this->file, $offset)->resolve($class);
    }

'''
source = source[:start] + facade_block + source[end:]

source = replace_once(
    source,
    "                foreach ($this->facadeAliases($fqcn, $fallback) as $alias) {\n"
    "                    $pattern = '/^\\s*'.preg_quote($variable, '/').'\\s*=\\s*'.preg_quote($alias, '/').'\\s*::/i';\n",
    "                foreach ($this->facadeAliases($fqcn, $fallback) as $alias) {\n"
    "                    if (! $this->facadeAliasValidAt($alias, $fqcn, $fallback, $token['offset'])) {\n"
    "                        continue;\n"
    "                    }\n"
    "                    $pattern = '/^\\s*'.preg_quote($variable, '/').'\\s*=\\s*'.preg_quote($alias, '/').'\\s*::/i';\n",
    'local facade handle context',
)

source = replace_once(
    source,
    "        foreach ($this->facadeAliases('Illuminate\\\\Support\\\\Facades\\\\Queue', 'Queue') as $alias) {\n"
    "            $pattern = '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\\s*::\\s*connection\\s*\\(/i';\n",
    "        foreach ($this->facadeAliases('Illuminate\\\\Support\\\\Facades\\\\Queue', 'Queue') as $alias) {\n"
    "            if (! $this->facadeAliasValidInContext($alias, 'Illuminate\\\\Support\\\\Facades\\\\Queue', 'Queue', $this->context)) {\n"
    "                continue;\n"
    "            }\n"
    "            $pattern = '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\\s*::\\s*connection\\s*\\(/i';\n",
    'queue connection facade context',
)

redis_anchor = (
    "        foreach ($this->facadeAliases('Illuminate\\\\Support\\\\Facades\\\\Redis', 'Redis') as $alias) {\n"
    "            $pattern = '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\\s*::(?:(?!;).)*?\\b(?P<method>'.$mutating.')\\s*\\(/is';"
)
if source.count(redis_anchor) != 1:
    raise SystemExit(f'redis scanner anchor: expected one match, found {source.count(redis_anchor)}')
redis_prefix = r'''        foreach ($this->facadeAliases('Illuminate\Support\Facades\Redis', 'Redis') as $alias) {
            $directPattern = '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::\s*(?P<method>[A-Za-z_][A-Za-z0-9_]*)\s*\(/i';
            foreach ($this->matches($directPattern) as $match) {
                $offset = $match['offset'];
                $tx = $this->eligibleTransaction($offset);
                if ($tx === null) {
                    continue;
                }
                $method = strtolower($this->captured($match, 'method'));
                $kind = OperationCatalog::redisMethodKind($method);
                if (in_array($kind, ['read', 'control', 'mutation'], true)) {
                    continue;
                }
                $this->appendFinding($findings, $offset, 'TG020', Severity::Warning,
                    "Redis::{$method}() cannot be proven read-only while a database transaction is open.",
                    'Move unknown Redis operations after commit or classify the command explicitly when it is read-only.', 'medium');
                $this->appendRetryFinding($findings, $offset, $tx, "Redis {$method}");
            }

            $connectionPattern = '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::\s*connection\s*\([^;]*?\)\s*->\s*(?P<method>[A-Za-z_][A-Za-z0-9_]*)\s*\(/is';
            foreach ($this->matches($connectionPattern) as $match) {
                $offset = $match['offset'];
                $tx = $this->eligibleTransaction($offset);
                if ($tx === null) {
                    continue;
                }
                $method = strtolower($this->captured($match, 'method'));
                $kind = OperationCatalog::redisMethodKind($method);
                if (in_array($kind, ['read', 'control', 'mutation'], true)) {
                    continue;
                }
                $this->appendFinding($findings, $offset, 'TG020', Severity::Warning,
                    "Redis connection method {$method}() cannot be proven read-only while a database transaction is open.",
                    'Move unknown Redis operations after commit or classify the command explicitly when it is read-only.', 'medium');
                $this->appendRetryFinding($findings, $offset, $tx, "Redis {$method}");
            }

            $pattern = '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::(?:(?!;).)*?\b(?P<method>'.$mutating.')\s*\(/is';'''
source = source.replace(redis_anchor, redis_prefix, 1)

source = replace_once(
    source,
    "            if ($handle['kind'] === 'redis' && in_array($method, ['pipeline', 'transaction'], true)) {\n"
    "                [$mutates, $unknown] = $this->redisCallbackMutationState($this->statementAt($offset));\n"
    "                if ($mutates || $unknown) {\n"
    "                    $this->appendFinding($findings, $offset, 'TG020', Severity::Warning,\n"
    "                        $mutates\n"
    "                            ? 'A Redis pipeline/transaction callback mutates Redis while a database transaction is open.'\n"
    "                            : 'A Redis pipeline/transaction callback cannot be proven read-only while a database transaction is open.',\n"
    "                        'Keep Redis callback mutations after the database commit.', $mutates ? 'high' : 'medium');\n"
    "                    $this->appendRetryFinding($findings, $offset, $tx, 'Redis callback mutation');\n"
    "                }\n\n"
    "                continue;\n"
    "            }\n\n"
    "            if ($handle['kind'] === 'process' && in_array($method, ['run', 'start', 'pipe', 'pool'], true)) {\n",
    "            if ($handle['kind'] === 'redis' && in_array($method, ['pipeline', 'transaction'], true)) {\n"
    "                [$mutates, $unknown] = $this->redisCallbackMutationState($this->statementAt($offset));\n"
    "                if ($mutates || $unknown) {\n"
    "                    $this->appendFinding($findings, $offset, 'TG020', Severity::Warning,\n"
    "                        $mutates\n"
    "                            ? 'A Redis pipeline/transaction callback mutates Redis while a database transaction is open.'\n"
    "                            : 'A Redis pipeline/transaction callback cannot be proven read-only while a database transaction is open.',\n"
    "                        'Keep Redis callback mutations after the database commit.', $mutates ? 'high' : 'medium');\n"
    "                    $this->appendRetryFinding($findings, $offset, $tx, 'Redis callback mutation');\n"
    "                }\n\n"
    "                continue;\n"
    "            }\n\n"
    "            if ($handle['kind'] === 'redis') {\n"
    "                $kind = OperationCatalog::redisMethodKind($method);\n"
    "                if (in_array($kind, ['read', 'control'], true)) {\n"
    "                    continue;\n"
    "                }\n"
    "                $this->appendFinding($findings, $offset, 'TG020', Severity::Warning,\n"
    "                    'Redis state may be mutated through a locally assigned Redis connection while a database transaction is open.',\n"
    "                    'Move unknown Redis operations after commit or classify the command explicitly when it is read-only.',\n"
    "                    $kind === 'mutation' ? 'high' : 'medium');\n"
    "                $this->appendRetryFinding($findings, $offset, $tx, 'Redis operation');\n\n"
    "                continue;\n"
    "            }\n\n"
    "            if ($handle['kind'] === 'process' && in_array($method, ['run', 'start', 'pipe', 'pool'], true)) {\n",
    'local Redis unknown fallback',
)

source = replace_once(
    source,
    "        if (preg_match('/->\\s*(?:'.$mutations.')\\s*\\(/i', $code) === 1) {\n"
    "            return [true, false];\n"
    "        }\n\n"
    "        $hasInlineCallable = preg_match('/(?:pipeline|transaction)\\s*\\(\\s*(?:static\\s+)?(?:function|fn)\\b/i', $code) === 1;\n",
    "        if (preg_match('/->\\s*(?:'.$mutations.')\\s*\\(/i', $code) === 1) {\n"
    "            return [true, false];\n"
    "        }\n\n"
    "        if (preg_match_all('/->\\s*(?<method>[A-Za-z_][A-Za-z0-9_]*)\\s*\\(/i', $code, $calls, PREG_SET_ORDER) > 0) {\n"
    "            foreach ($calls as $call) {\n"
    "                $kind = OperationCatalog::redisMethodKind((string) ($call['method'] ?? ''));\n"
    "                if (in_array($kind, ['read', 'control'], true)) {\n"
    "                    continue;\n"
    "                }\n"
    "                if ($kind === 'mutation') {\n"
    "                    return [true, false];\n"
    "                }\n\n"
    "                return [false, true];\n"
    "            }\n"
    "        }\n\n"
    "        $hasInlineCallable = preg_match('/(?:pipeline|transaction)\\s*\\(\\s*(?:static\\s+)?(?:function|fn)\\b/i', $code) === 1;\n",
    'Redis callback unknown fallback',
)

scanner_path.write_text(source)

metadata_path = Path('src/Analysis/ClassMetadataIndex.php')
metadata = metadata_path.read_text()

old = r'''    /** @return array<string, string> */
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
'''
new = r'''    /** @return array<string, string> */
    public function notificationChannelConnections(string $class): array
    {
        $seen = [];
        $current = ltrim($class, '\\');

        while ($current !== '') {
            $key = strtolower($current);
            if (isset($seen[$key])) {
                return [];
            }
            $seen[$key] = true;
            if (array_key_exists($key, $this->notificationChannelConnections)) {
                return $this->notificationChannelConnections[$key];
            }

            $metadata = $this->metadata($current);
            if ($metadata?->parent === null) {
                return [];
            }
            $current = ltrim($metadata->parent, '\\');
        }

        return [];
    }

    public function modelConnection(string $class): ?string
    {
        $seen = [];
        $current = ltrim($class, '\\');

        while ($current !== '') {
            $key = strtolower($current);
            if (isset($seen[$key])) {
                return null;
            }
            $seen[$key] = true;
            if (array_key_exists($key, $this->modelConnections)) {
                return $this->modelConnections[$key];
            }

            $metadata = $this->metadata($current);
            if ($metadata?->parent === null) {
                return null;
            }
            $current = ltrim($metadata->parent, '\\');
        }

        return null;
    }

    public function modelRelationTarget(string $class, string $relation): ?string
    {
        $seen = [];
        $current = ltrim($class, '\\');
        $relation = strtolower($relation);

        while ($current !== '') {
            $key = strtolower($current);
            if (isset($seen[$key])) {
                return null;
            }
            $seen[$key] = true;
            if (isset($this->modelRelations[$key][$relation])) {
                return $this->modelRelations[$key][$relation];
            }

            $metadata = $this->metadata($current);
            if ($metadata?->parent === null) {
                return null;
            }
            $current = ltrim($metadata->parent, '\\');
        }

        return null;
    }
'''
metadata = replace_once(metadata, old, new, 'cycle-safe metadata ancestry')
metadata = replace_once(
    metadata,
    "$fallbackImport = $context->imports[$fallback] ?? null;",
    "$fallbackImport = $context->importForAlias($fallback);",
    'metadata facade alias fallback',
)
metadata_path.write_text(metadata)
