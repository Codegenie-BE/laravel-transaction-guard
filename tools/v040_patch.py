from __future__ import annotations

from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[1]


def read(path: str) -> str:
    return (ROOT / path).read_text()


def write(path: str, content: str) -> None:
    target = ROOT / path
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_text(content)


def replace_once(path: str, old: str, new: str) -> None:
    text = read(path)
    if old not in text:
        raise RuntimeError(f"missing anchor in {path}: {old[:160]!r}")
    write(path, text.replace(old, new, 1))


def regex_once(path: str, pattern: str, replacement: str) -> None:
    text = read(path)
    updated, count = re.subn(pattern, replacement, text, count=1, flags=re.S)
    if count != 1:
        raise RuntimeError(f"regex anchor in {path} matched {count}: {pattern[:120]!r}")
    write(path, updated)


def insert_before(path: str, anchor: str, block: str) -> None:
    text = read(path)
    if anchor not in text:
        raise RuntimeError(f"missing insertion anchor in {path}: {anchor[:120]!r}")
    write(path, text.replace(anchor, block + anchor, 1))


# ---------------------------------------------------------------------------
# Focused analyzer components: central operation catalogs, driver policy,
# static literal reduction, class-attribute and model-relation extraction.
# ---------------------------------------------------------------------------
write('src/Analysis/OperationCatalog.php', r'''<?php

declare(strict_types=1);

namespace Codegenie\TransactionGuard\Analysis;

final class OperationCatalog
{
    public const CACHE_MUTATIONS = [
        'put', 'set', 'putMany', 'setMultiple', 'add', 'forever', 'remember', 'rememberWithWarmth',
        'rememberForever', 'sear', 'flexible', 'touch', 'forget', 'delete', 'deleteMultiple', 'clear',
        'flush', 'flushLocks', 'increment', 'decrement', 'pull', 'withoutOverlapping',
    ];

    public const CACHE_LOCK_TERMINALS = ['get', 'block', 'release', 'forceRelease'];

    public const RATE_LIMITER_MUTATIONS = ['attempt', 'hit', 'increment', 'decrement', 'clear', 'resetAttempts'];

    public const REDIS_MUTATIONS = [
        'set', 'setex', 'psetex', 'mset', 'del', 'unlink', 'incr', 'incrby', 'incrbyfloat', 'decr',
        'decrby', 'hset', 'hmset', 'hdel', 'hincrby', 'lpush', 'rpush', 'lpop', 'rpop', 'ltrim',
        'sadd', 'srem', 'smove', 'zadd', 'zincrby', 'zrem', 'expire', 'pexpire', 'persist', 'flushdb',
        'flushall', 'publish', 'xadd', 'xdel', 'xtrim', 'pfadd', 'pfmerge', 'setbit', 'bitop', 'geoadd',
    ];

    public const REDIS_MUTATING_COMMANDS = [
        'SET', 'SETEX', 'PSETEX', 'MSET', 'DEL', 'UNLINK', 'INCR', 'INCRBY', 'INCRBYFLOAT', 'DECR',
        'DECRBY', 'HSET', 'HMSET', 'HDEL', 'HINCRBY', 'LPUSH', 'RPUSH', 'LPOP', 'RPOP', 'LTRIM',
        'SADD', 'SREM', 'SMOVE', 'ZADD', 'ZINCRBY', 'ZREM', 'EXPIRE', 'PEXPIRE', 'PERSIST', 'FLUSHDB',
        'FLUSHALL', 'PUBLISH', 'XADD', 'XDEL', 'XTRIM', 'PFADD', 'PFMERGE', 'SETBIT', 'BITOP', 'GEOADD',
    ];

    public const REDIS_READ_COMMANDS = [
        'GET', 'MGET', 'EXISTS', 'TTL', 'PTTL', 'HGET', 'HGETALL', 'HMGET', 'HLEN', 'LRANGE', 'LLEN',
        'SCARD', 'SMEMBERS', 'SISMEMBER', 'ZRANGE', 'ZREVRANGE', 'ZSCORE', 'ZCARD', 'XRANGE', 'XREVRANGE',
        'XLEN', 'PFCOUNT', 'GETBIT', 'BITCOUNT', 'GEODIST', 'GEOHASH', 'GEOPOS', 'TYPE', 'PING',
    ];

    public const REDIS_SCRIPT_COMMANDS = ['EVAL', 'EVALSHA', 'FCALL'];

    public const QUERY_MUTATIONS = [
        'insert', 'insertGetId', 'insertOrIgnore', 'insertUsing', 'update', 'updateOrInsert', 'upsert',
        'delete', 'truncate', 'increment', 'decrement', 'incrementEach', 'decrementEach', 'statement',
        'unprepared', 'affectingStatement',
    ];

    public const ELOQUENT_STATIC_MUTATIONS = [
        'create', 'forceCreate', 'updateOrCreate', 'firstOrCreate', 'upsert', 'insert', 'insertOrIgnore',
        'update', 'delete', 'destroy', 'truncate', 'increment', 'decrement', 'incrementEach', 'decrementEach',
        'forceDelete', 'restore',
    ];

    public const ELOQUENT_INSTANCE_MUTATIONS = [
        'save', 'saveQuietly', 'update', 'updateQuietly', 'delete', 'deleteQuietly', 'forceDelete',
        'forceDeleteQuietly', 'restore', 'restoreQuietly', 'touch', 'touchQuietly', 'push', 'pushQuietly',
        'increment', 'decrement',
    ];

    public const RELATION_MUTATIONS = [
        'attach', 'detach', 'sync', 'syncWithoutDetaching', 'syncWithPivotValues', 'toggle',
        'updateExistingPivot', 'save', 'saveMany', 'create', 'createMany', 'updateOrCreate', 'firstOrCreate',
    ];

    /** @param list<string> $values */
    public static function alternation(array $values): string
    {
        return implode('|', array_map(static fn (string $value): string => preg_quote($value, '/'), $values));
    }

    public static function redisCommandKind(string $command): string
    {
        $command = strtoupper($command);
        if (in_array($command, self::REDIS_MUTATING_COMMANDS, true)) {
            return 'mutation';
        }
        if (in_array($command, self::REDIS_READ_COMMANDS, true)) {
            return 'read';
        }
        if (in_array($command, self::REDIS_SCRIPT_COMMANDS, true)) {
            return 'script';
        }

        return 'unknown';
    }
}
''')

write('src/Analysis/DatabaseDriverPolicy.php', r'''<?php

declare(strict_types=1);

namespace Codegenie\TransactionGuard\Analysis;

final class DatabaseDriverPolicy
{
    /** @return array{severity:Severity,semantics:string} */
    public static function ddl(?string $driver): array
    {
        if ($driver === null || $driver === '') {
            return ['severity' => Severity::Critical, 'semantics' => 'unknown'];
        }

        return match (strtolower($driver)) {
            'mysql', 'mariadb' => ['severity' => Severity::Critical, 'semantics' => 'implicit-commit'],
            'pgsql' => ['severity' => Severity::Warning, 'semantics' => 'transactional-ddl'],
            'sqlite' => ['severity' => Severity::Warning, 'semantics' => 'transactional-ddl-with-limitations'],
            'sqlsrv' => ['severity' => Severity::Warning, 'semantics' => 'mostly-transactional-ddl'],
            default => ['severity' => Severity::Warning, 'semantics' => 'driver-specific'],
        };
    }
}
''')

write('src/Analysis/StaticExpressionResolver.php', r'''<?php

declare(strict_types=1);

namespace Codegenie\TransactionGuard\Analysis;

final class StaticExpressionResolver
{
    public static function booleanFirstArgument(string $statement, string $method): ?bool
    {
        if (preg_match('/(?:->|::)\s*'.preg_quote($method, '/').'\s*\(\s*(true|false)\b/i', $statement, $match) !== 1) {
            return null;
        }

        return strtolower($match[1]) === 'true';
    }

    public static function firstStringArgument(string $call): ?string
    {
        try {
            $tokens = token_get_all('<?php '.$call, TOKEN_PARSE);
        } catch (\ParseError) {
            return null;
        }

        $inside = false;
        $parts = [];
        $expectValue = true;
        $heredoc = null;

        foreach ($tokens as $token) {
            if (! $inside) {
                if ($token === '(') {
                    $inside = true;
                }
                continue;
            }

            if (is_array($token) && in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            if ($heredoc !== null) {
                if (is_array($token) && $token[0] === T_END_HEREDOC) {
                    $parts[] = $heredoc;
                    $heredoc = null;
                    $expectValue = false;
                    continue;
                }
                if (is_array($token) && $token[0] === T_ENCAPSED_AND_WHITESPACE) {
                    $heredoc .= $token[1];
                    continue;
                }
                return null;
            }

            if ($token === ')' || $token === ',') {
                break;
            }
            if ($token === '.') {
                if ($expectValue) {
                    return null;
                }
                $expectValue = true;
                continue;
            }
            if (! $expectValue) {
                return null;
            }
            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $literal = $token[1];
                if (strlen($literal) < 2) {
                    return null;
                }
                $parts[] = stripcslashes(substr($literal, 1, -1));
                $expectValue = false;
                continue;
            }
            if (is_array($token) && $token[0] === T_START_HEREDOC) {
                $heredoc = '';
                continue;
            }

            return null;
        }

        return $parts === [] || $expectValue ? null : implode('', $parts);
    }
}
''')

write('src/Analysis/MetadataAttributeResolver.php', r'''<?php

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

        $aliases = [$fallback, '\\'.ltrim($fqcn, '\\')];
        foreach ($context->imports as $alias => $import) {
            if (strcasecmp(ltrim($import, '\\'), ltrim($fqcn, '\\')) === 0) {
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
''')

write('src/Analysis/ModelRelationExtractor.php', r'''<?php

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
''')

# ---------------------------------------------------------------------------
# Class metadata: pre-dispatch lifecycle, debounce metadata, event traits and
# simple relationship metadata.
# ---------------------------------------------------------------------------
replace_once('src/Analysis/ClassMetadata.php',
'''        public ?string $queueName = null,
        public ?bool $afterCommitOverride = null,
    ) {}
''',
'''        public ?string $queueName = null,
        public ?bool $afterCommitOverride = null,
        public bool $debounced = false,
    ) {}
''')

insert_before('src/Analysis/ClassMetadata.php',
'''    public function explicitlyBeforeCommit(): bool
''',
'''    public function preparesForDispatch(): bool
    {
        return $this->implements('Illuminate\\Contracts\\Queue\\PreparesForDispatch');
    }

    public function uniqueBeforeDispatch(): bool
    {
        return $this->implements('Illuminate\\Contracts\\Queue\\ShouldBeUnique');
    }

    public function usesEventDispatchableTrait(): bool
    {
        foreach ($this->traits as $trait) {
            if (strcasecmp(ltrim($trait, '\\'), 'Illuminate\\Foundation\\Events\\Dispatchable') === 0) {
                return true;
            }
        }

        return false;
    }

''')

replace_once('src/Analysis/ClassMetadataIndex.php',
'''    /** @var array<string, string> */
    private array $modelConnections = [];
''',
'''    /** @var array<string, string> */
    private array $modelConnections = [];

    /** @var array<string, array<string, string>> */
    private array $modelRelations = [];
''')

insert_before('src/Analysis/ClassMetadataIndex.php',
'''    public function isEloquentModel(string $class): bool
''',
'''    public function modelRelationTarget(string $class, string $relation): ?string
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
            if (strcasecmp(ltrim($trait, '\\'), 'Illuminate\\Foundation\\Events\\Dispatchable') === 0) {
                return true;
            }
        }

        return false;
    }

''')

replace_once('src/Analysis/ClassMetadataIndex.php',
'''            $attributeQueue = $this->queueAttributeForDeclaration($source, $tokens[$i]['offset'], $context);
            $attributeConnection = $this->connectionAttributeForDeclaration($source, $tokens[$i]['offset'], $context);
''',
'''            $attributeQueue = $this->queueAttributeForDeclaration($source, $tokens[$i]['offset'], $context);
            $attributeConnection = $this->connectionAttributeForDeclaration($source, $tokens[$i]['offset'], $context);
            $debounced = MetadataAttributeResolver::hasClassAttribute(
                $source,
                $tokens[$i]['offset'],
                $context,
                'Illuminate\\Queue\\Attributes\\DebounceFor',
                'DebounceFor',
            );
''')

replace_once('src/Analysis/ClassMetadataIndex.php',
'''            if ($modelConnection !== null) {
                $this->modelConnections[strtolower($fqcn)] = $modelConnection;
            }

            $this->classes[strtolower($fqcn)] = new ClassMetadata(
''',
'''            if ($modelConnection !== null) {
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
''')

replace_once('src/Analysis/ClassMetadataIndex.php',
'''                queueName: $queueName,
                afterCommitOverride: $afterCommitOverride,
            );
''',
'''                queueName: $queueName,
                afterCommitOverride: $afterCommitOverride,
                debounced: $debounced,
            );
''')

# Both metadata-copy sites must preserve the new debounce flag.
text = read('src/Analysis/ClassMetadataIndex.php')
text = text.replace(
'''                queueName: $metadata->queueName,
                afterCommitOverride: $metadata->afterCommitOverride,
            );''',
'''                queueName: $metadata->queueName,
                afterCommitOverride: $metadata->afterCommitOverride,
                debounced: $metadata->debounced,
            );''')
text = text.replace(
'''            queueName: $metadata->queueName,
            afterCommitOverride: $metadata->afterCommitOverride ?? $parent->afterCommitOverride,
        );''',
'''            queueName: $metadata->queueName,
            afterCommitOverride: $metadata->afterCommitOverride ?? $parent->afterCommitOverride,
            debounced: $metadata->debounced || $parent->debounced,
        );''')
write('src/Analysis/ClassMetadataIndex.php', text)

# ---------------------------------------------------------------------------
# Config: strict unresolved transaction callbacks + robust PCRE delimiter
# handling. Diagnostic rules remain undisableable.
# ---------------------------------------------------------------------------
replace_once('src/Analysis/AnalysisConfig.php',
'''        public bool $allowEmptyScan = false,
        public string $projectRoot = '',
''',
'''        public bool $allowEmptyScan = false,
        public bool $failOnUnresolvedTransaction = false,
        public string $projectRoot = '',
''')

regex_once('src/Analysis/AnalysisConfig.php',
r'''        foreach \(\$this->customSideEffectPatterns as \$pattern\) \{.*?            \$compiledCustomSideEffectPatterns\[\] = \$regex;\n        \}\n''',
'''        foreach ($this->customSideEffectPatterns as $pattern) {
            $regex = $this->compileCustomRegex($pattern);
            set_error_handler(static fn (): bool => true);
            try {
                $valid = preg_match($regex, '') !== false;
            } finally {
                restore_error_handler();
            }

            if (! $valid) {
                throw new \\InvalidArgumentException("Invalid custom side-effect regular expression [{$pattern}].");
            }
            $compiledCustomSideEffectPatterns[] = $regex;
        }
''')

insert_before('src/Analysis/AnalysisConfig.php',
'''    /** @return list<string> */
    public function customRegexes(): array
''',
'''    private function compileCustomRegex(string $pattern): string
    {
        $pattern = trim($pattern);
        if ($pattern === '') {
            throw new \\InvalidArgumentException('Custom side-effect regular expressions cannot be empty.');
        }

        $first = $pattern[0];
        if (! ctype_alnum($first) && ! ctype_space($first) && $first !== '\\\\') {
            $last = strrpos($pattern, $first);
            if ($last !== false && $last > 0 && preg_match('/^[imsxuADUJu]*$/', substr($pattern, $last + 1)) === 1) {
                return $pattern;
            }
        }

        foreach (['~', '#', '%', '!', '@', ';'] as $delimiter) {
            if (! str_contains($pattern, $delimiter)) {
                return $delimiter.$pattern.$delimiter;
            }
        }

        return '/'.str_replace('/', '\\/', $pattern).'/';
    }

''')

replace_once('config/transaction-guard.php',
'''    /* Fail instead of reporting a clean scan when no PHP files are discovered. */
    'allow_empty_scan' => false,

    /* info | warning | error | critical | never */
''',
'''    /* Fail instead of reporting a clean scan when no PHP files are discovered. */
    'allow_empty_scan' => false,

    /* Treat unresolved DB::transaction() callbacks (TG014) as CI failures. */
    'fail_on_unresolved_transaction' => false,

    /* info | warning | error | critical | never */
''')

replace_once('src/Console/TransactionGuardCommand.php',
'''                allowEmptyScan: (bool) config('transaction-guard.allow_empty_scan', false),
                projectRoot: base_path(),
''',
'''                allowEmptyScan: (bool) config('transaction-guard.allow_empty_scan', false),
                failOnUnresolvedTransaction: (bool) config('transaction-guard.fail_on_unresolved_transaction', false),
                projectRoot: base_path(),
''')

replace_once('src/Console/TransactionGuardCommand.php',
'''        if ($result->hasDiagnostics()) {
            return self::FAILURE;
        }
        if ($failOn === 'never') {
''',
'''        if ($result->hasDiagnostics()) {
            return self::FAILURE;
        }
        if ($analysisConfig->failOnUnresolvedTransaction
            && array_any($result->findings, static fn (Finding $finding): bool => $finding->rule === 'TG014')) {
            return self::FAILURE;
        }
        if ($failOn === 'never') {
''')

# array_any is PHP 8.4+, while the package supports 8.2: use a private helper.
replace_once('src/Console/TransactionGuardCommand.php',
'''        if ($analysisConfig->failOnUnresolvedTransaction
            && array_any($result->findings, static fn (Finding $finding): bool => $finding->rule === 'TG014')) {
            return self::FAILURE;
        }
''',
'''        if ($analysisConfig->failOnUnresolvedTransaction && $this->containsRule($result->findings, 'TG014')) {
            return self::FAILURE;
        }
''')
insert_before('src/Console/TransactionGuardCommand.php',
'''    /** @return list<string> */
    private function stringArguments(string $name): array
''',
'''    /** @param list<Finding> $findings */
    private function containsRule(array $findings, string $rule): bool
    {
        foreach ($findings as $finding) {
            if ($finding->rule === $rule) {
                return true;
            }
        }

        return false;
    }

''')

# ---------------------------------------------------------------------------
# Discovery integrity: turn traversal failures into a non-baselineable TG903
# diagnostic instead of silently skipping directories.
# ---------------------------------------------------------------------------
replace_once('src/TransactionGuard.php',
'''final class TransactionGuard
{
    public function __construct(private readonly AnalysisConfig $config = new AnalysisConfig) {}
''',
'''final class TransactionGuard
{
    /** @var array<string, Finding> */
    private array $discoveryDiagnostics = [];

    public function __construct(private readonly AnalysisConfig $config = new AnalysisConfig) {}
''')

replace_once('src/TransactionGuard.php',
'''        $files = $this->discoverPhpFiles($paths, $excludePatterns);
        if ($files === [] && ! $this->config->allowEmptyScan) {
''',
'''        $this->discoveryDiagnostics = [];
        $files = $this->discoverPhpFiles($paths, $excludePatterns);
        if ($files === [] && $this->discoveryDiagnostics === [] && ! $this->config->allowEmptyScan) {
''')

replace_once('src/TransactionGuard.php',
'''        $diagnostics = [];
''',
'''        $diagnostics = array_values($this->discoveryDiagnostics);
''')

replace_once('src/TransactionGuard.php',
'''            $directory = new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS);
            $filter = new RecursiveCallbackFilterIterator(
                $directory,
                fn (SplFileInfo $entry): bool => ! $this->excluded($entry->getPathname(), $excludePatterns),
            );
''',
'''            try {
                $directory = new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS);
            } catch (\\UnexpectedValueException $exception) {
                $this->recordDiscoveryDiagnostic($path, $exception->getMessage());
                continue;
            }
            $filter = new RecursiveCallbackFilterIterator(
                $directory,
                function (SplFileInfo $entry) use ($excludePatterns): bool {
                    if ($entry->isDir() && ! is_readable($entry->getPathname())) {
                        $this->recordDiscoveryDiagnostic($entry->getPathname(), 'Directory is not readable.');
                        return false;
                    }

                    return ! $this->excluded($entry->getPathname(), $excludePatterns);
                },
            );
''')

insert_before('src/TransactionGuard.php',
'''    /** @param  list<string>  $patterns */
    private function excluded(string $path, array $patterns): bool
''',
'''    private function recordDiscoveryDiagnostic(string $path, string $message): void
    {
        $normalized = realpath($path) ?: $path;
        $this->discoveryDiagnostics[$normalized] ??= new Finding(
            rule: 'TG903',
            severity: \\Codegenie\\TransactionGuard\\Analysis\\Severity::Error,
            message: 'Unable to traverse requested source path: '.$message,
            file: $normalized,
            line: 1,
            snippet: '',
            remediation: 'Fix source-directory permissions or exclusions so Transaction Guard can analyze the complete requested tree.',
            confidence: 'high',
            projectRoot: $this->config->projectRoot,
        );
    }

''')

# ---------------------------------------------------------------------------
# Baseline writes are atomic; SourceIndex handles CR, LF and CRLF consistently.
# ---------------------------------------------------------------------------
replace_once('src/Analysis/Baseline.php',
'''        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
        if (file_put_contents($path, $encoded) === false) {
            throw new \\RuntimeException("Unable to write baseline [{$path}].");
        }
''',
'''        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
        $temporary = tempnam($directory, '.transaction-guard-baseline-');
        if ($temporary === false) {
            throw new \\RuntimeException("Unable to create a temporary baseline file in [{$directory}].");
        }

        try {
            if (file_put_contents($temporary, $encoded, LOCK_EX) === false || ! rename($temporary, $path)) {
                throw new \\RuntimeException("Unable to atomically write baseline [{$path}].");
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
''')

regex_once('src/Analysis/SourceIndex.php',
r'''        \$length = strlen\(\$source\);\n        for \(\$offset = 0; \$offset < \$length; \$offset\+\+\) \{\n            if \(\$source\[\$offset\] === "\\n"\) \{\n                \$this->lineStarts\[\] = \$offset \+ 1;\n            \}\n        \}\n''',
'''        $length = strlen($source);
        for ($offset = 0; $offset < $length; $offset++) {
            if ($source[$offset] === "\\r") {
                if ($offset + 1 < $length && $source[$offset + 1] === "\\n") {
                    $offset++;
                }
                $this->lineStarts[] = $offset + 1;
            } elseif ($source[$offset] === "\\n") {
                $this->lineStarts[] = $offset + 1;
            }
        }
''')

# ---------------------------------------------------------------------------
# Rule catalog: canonical severity/category/remediation metadata and new rules.
# ---------------------------------------------------------------------------
replace_once('src/Analysis/RuleCatalog.php',
'''        'TG021' => ['title' => 'Cross-connection database write', 'description' => 'A database write uses a different connection from the active transaction.'],
        'TG100' => ['title' => 'Configured custom side effect', 'description' => 'A configured project-specific side effect runs inside a transaction.'],
        'TG900' => ['title' => 'Unreadable source file', 'description' => 'The analyzer could not read a requested PHP source file.'],
        'TG901' => ['title' => 'PHP parse failure', 'description' => 'The analyzer could not parse a requested PHP source file.'],
        'TG902' => ['title' => 'Analyzer regular-expression failure', 'description' => 'A scanner regular expression failed at analysis time and results may be incomplete.'],
''',
'''        'TG021' => ['title' => 'Cross-connection database write', 'description' => 'A database write uses a different connection from the active transaction.'],
        'TG022' => ['title' => 'Pre-dispatch hook before commit', 'description' => 'A PreparesForDispatch hook executes synchronously before commit-aware queue dispatch can defer the job.'],
        'TG023' => ['title' => 'Queue cache lock before commit', 'description' => 'PendingDispatch may acquire unique/debounce cache state before the surrounding database transaction commits.'],
        'TG100' => ['title' => 'Configured custom side effect', 'description' => 'A configured project-specific side effect runs inside a transaction.'],
        'TG900' => ['title' => 'Unreadable source file', 'description' => 'The analyzer could not read a requested PHP source file.'],
        'TG901' => ['title' => 'PHP parse failure', 'description' => 'The analyzer could not parse a requested PHP source file.'],
        'TG902' => ['title' => 'Analyzer regular-expression failure', 'description' => 'A scanner regular expression failed at analysis time and results may be incomplete.'],
        'TG903' => ['title' => 'Source traversal failure', 'description' => 'The analyzer could not traverse part of a requested source tree.'],
''')

replace_once('src/Analysis/RuleCatalog.php',
'''    /** @return list<string> */
    public static function ids(): array
''',
'''    /** @var array<string, array{severity:string,category:string,remediation:string}> */
    private const DEFAULTS = [
        'TG001' => ['severity' => 'error / warning', 'category' => 'queue', 'remediation' => 'Use after-commit dispatch or move the dispatch after the transaction.'],
        'TG002' => ['severity' => 'warning', 'category' => 'events', 'remediation' => 'Dispatch after commit or implement ShouldDispatchAfterCommit.'],
        'TG003' => ['severity' => 'error', 'category' => 'mail', 'remediation' => 'Send or queue mail after commit.'],
        'TG004' => ['severity' => 'error', 'category' => 'notifications', 'remediation' => 'Deliver notifications after commit.'],
        'TG005' => ['severity' => 'error', 'category' => 'broadcasting', 'remediation' => 'Broadcast after commit.'],
        'TG006' => ['severity' => 'error / warning', 'category' => 'external-io', 'remediation' => 'Perform outbound HTTP after commit.'],
        'TG007' => ['severity' => 'warning', 'category' => 'filesystem', 'remediation' => 'Move filesystem mutations after commit or compensate them.'],
        'TG008' => ['severity' => 'warning', 'category' => 'cache', 'remediation' => 'Mutate cache and cache locks after commit.'],
        'TG009' => ['severity' => 'error', 'category' => 'process', 'remediation' => 'Run external processes after commit.'],
        'TG010' => ['severity' => 'error', 'category' => 'queue', 'remediation' => 'Remove beforeCommit() unless pre-commit dispatch is intentional.'],
        'TG011' => ['severity' => 'warning / critical', 'category' => 'retries', 'remediation' => 'Keep retryable transaction callbacks free of irreversible effects.'],
        'TG012' => ['severity' => 'critical / warning', 'category' => 'database', 'remediation' => 'Keep DDL and implicit-commit statements outside application transactions.'],
        'TG013' => ['severity' => 'critical', 'category' => 'database', 'remediation' => 'Close manual transactions on every path or use DB::transaction().'],
        'TG014' => ['severity' => 'info', 'category' => 'analysis', 'remediation' => 'Use an analyzable transaction callback or enable strict unresolved-callback CI.'],
        'TG016' => ['severity' => 'warning', 'category' => 'queue', 'remediation' => 'Move synchronous dispatch outside the transaction.'],
        'TG017' => ['severity' => 'warning', 'category' => 'queue', 'remediation' => 'Use afterCommit() instead of after-response timing.'],
        'TG018' => ['severity' => 'warning', 'category' => 'concurrency', 'remediation' => 'Start concurrent/deferred work after commit.'],
        'TG020' => ['severity' => 'warning / error', 'category' => 'redis', 'remediation' => 'Move Redis mutations after commit.'],
        'TG021' => ['severity' => 'error', 'category' => 'database', 'remediation' => 'Use the transaction connection for all atomic writes.'],
        'TG022' => ['severity' => 'warning', 'category' => 'queue', 'remediation' => 'Keep prepareForDispatch() side-effect free or dispatch after commit.'],
        'TG023' => ['severity' => 'warning', 'category' => 'queue', 'remediation' => 'Create unique/debounce PendingDispatch jobs after commit when pre-commit cache state is unacceptable.'],
        'TG100' => ['severity' => 'warning', 'category' => 'custom', 'remediation' => 'Move the configured side effect after commit.'],
        'TG900' => ['severity' => 'error', 'category' => 'diagnostic', 'remediation' => 'Fix file readability.'],
        'TG901' => ['severity' => 'error', 'category' => 'diagnostic', 'remediation' => 'Fix PHP syntax before analysis.'],
        'TG902' => ['severity' => 'error', 'category' => 'diagnostic', 'remediation' => 'Report the analyzer regex failure.'],
        'TG903' => ['severity' => 'error', 'category' => 'diagnostic', 'remediation' => 'Fix source-tree traversal permissions or exclusions.'],
    ];

    /** @return list<string> */
    public static function ids(): array
''')

replace_once('src/Analysis/RuleCatalog.php',
'''        return in_array(strtoupper($rule), ['TG900', 'TG901', 'TG902'], true);
''',
'''        return in_array(strtoupper($rule), ['TG900', 'TG901', 'TG902', 'TG903'], true);
''')

replace_once('src/Analysis/RuleCatalog.php',
'''    /** @return array{title:string,description:string} */
    public static function definition(string $rule): array
''',
'''    /** @return array{title:string,description:string,severity:string,category:string,remediation:string} */
    public static function definition(string $rule): array
''')
replace_once('src/Analysis/RuleCatalog.php',
'''        return self::RULES[$rule];
''',
'''        return [...self::RULES[$rule], ...self::DEFAULTS[$rule]];
''')

# ---------------------------------------------------------------------------
# SourceScanner: lifecycle hooks, central catalogs, cache locks/rate limiter,
# Redis callback/command classification, local model connection overrides,
# relation writes, literal conditional dispatch and driver/static SQL policy.
# ---------------------------------------------------------------------------
replace_once('src/Analysis/SourceScanner.php',
'''        $this->scanCache($findings);
        $this->scanRedis($findings);
''',
'''        $this->scanCache($findings);
        $this->scanRateLimiter($findings);
        $this->scanRedis($findings);
''')

replace_once('src/Analysis/SourceScanner.php',
'''            $key = $finding->rule.'|'.$finding->line.'|'.$finding->snippet;
''',
'''            $key = $finding->rule.'|'.$finding->line.'|'.($finding->column ?? 0).'|'.$finding->snippet;
''')

# Static job dispatch: fold literal conditions and report PendingDispatch lifecycle.
replace_once('src/Analysis/SourceScanner.php',
'''                $method = $this->captured($match, 'method');
                $statement = $this->statementAt($offset);
                $globalDispatchHelper = $method === '';
''',
'''                $method = $this->captured($match, 'method');
                $statement = $this->statementAt($offset);
                if ($this->conditionalDispatchIsSkipped($statement, $method)) {
                    continue;
                }
                $globalDispatchHelper = $method === '';
''')

replace_once('src/Analysis/SourceScanner.php',
'''                if (in_array($method, ['dispatchSync', 'dispatch_sync'], true)) {
''',
'''                if ($globalDispatchHelper || in_array($method, ['dispatch', 'dispatchIf', 'dispatchUnless', 'dispatchAfterResponse'], true)) {
                    $this->appendPendingDispatchLifecycleFindings($findings, $offset, $tx, $metadata);
                }

                if (in_array($method, ['dispatchSync', 'dispatch_sync'], true)) {
''')

# Pending chains literal dispatchIf/Unless.
replace_once('src/Analysis/SourceScanner.php',
'''            $statement = $this->statementAt($offset);
            if (preg_match('/->\\s*dispatch(?:If|Unless)?\\s*\\(/i', $statement) !== 1) {
                continue;
            }
''',
'''            $statement = $this->statementAt($offset);
            if (preg_match('/->\\s*dispatch(?:If|Unless)?\\s*\\(/i', $statement) !== 1) {
                continue;
            }
            if ($this->conditionalDispatchIsSkipped($statement, 'dispatchIf')
                || $this->conditionalDispatchIsSkipped($statement, 'dispatchUnless')) {
                continue;
            }
''')

# Static event dispatchable heuristic becomes metadata/trait based and folds literals.
replace_once('src/Analysis/SourceScanner.php',
'''            $metadata = $this->classIndex->metadata($class);
            $looksLikeEvent = $metadata?->eventAfterCommit() === true || str_contains(strtolower($class), '\\\\events\\\\');
            if (! $looksLikeEvent || $metadata?->eventAfterCommit() === true) {
''',
'''            $metadata = $this->classIndex->metadata($class);
            $method = $this->captured($match, 'method');
            if ($this->conditionalDispatchIsSkipped($this->statementAt($offset), $method)) {
                continue;
            }
            $looksLikeEvent = $this->classIndex->isDispatchableEvent($class);
            if (! $looksLikeEvent || $metadata?->eventAfterCommit() === true) {
''')

# Local payload resolver supports namespace-relative names.
replace_once('src/Analysis/SourceScanner.php',
'''            if ($name === null || ! in_array($this->tokens[$name]['id'], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
''',
'''            if ($name === null || ! in_array($this->tokens[$name]['id'], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
''')

# Cache/local handle catalogs and cache-lock handles.
replace_once('src/Analysis/SourceScanner.php',
'''                    $connection = null;
                    if ($kind === 'db') {
''',
'''                    $connection = null;
                    if ($kind === 'cache' && preg_match('/::\\s*(?:lock|restoreLock)\\s*\\(/i', $code) === 1) {
                        $kind = 'cache_lock';
                    }
                    if ($kind === 'db') {
''')

regex_once('src/Analysis/SourceScanner.php',
r'''            if \(\$handle\['kind'\] === 'cache' && in_array\(\$method, \[.*?            \], true\)\) \{''',
'''            if ($handle['kind'] === 'cache' && in_array($method, array_map('strtolower', OperationCatalog::CACHE_MUTATIONS), true)) {''')

insert_before('src/Analysis/SourceScanner.php',
'''            if ($handle['kind'] === 'redis' && in_array($method, [
''',
'''            if ($handle['kind'] === 'cache_lock' && in_array($method, array_map('strtolower', OperationCatalog::CACHE_LOCK_TERMINALS), true)) {
                $this->appendFinding($findings, $offset, 'TG008', Severity::Warning,
                    'A Laravel cache lock is acquired or released while a database transaction is open.',
                    'Acquire/release cache locks after commit unless the lock lifecycle is explicitly compensatable.', 'high');
                $this->appendRetryFinding($findings, $offset, $tx, 'cache lock mutation');

                continue;
            }

''')

# Replace local Redis static list including pipeline/transaction with central known mutations.
regex_once('src/Analysis/SourceScanner.php',
r'''            if \(\$handle\['kind'\] === 'redis' && in_array\(\$method, \[.*?            \], true\)\) \{''',
'''            if ($handle['kind'] === 'redis' && in_array($method, array_map('strtolower', OperationCatalog::REDIS_MUTATIONS), true)) {''')

# Handle local Redis pipeline/transaction callbacks separately.
insert_before('src/Analysis/SourceScanner.php',
'''            if ($handle['kind'] === 'process' && in_array($method, ['run', 'start', 'pipe', 'pool'], true)) {
''',
'''            if ($handle['kind'] === 'redis' && in_array($method, ['pipeline', 'transaction'], true)) {
                [$mutates, $unknown] = $this->redisCallbackMutationState($this->statementAt($offset));
                if ($mutates || $unknown) {
                    $this->appendFinding($findings, $offset, 'TG020', Severity::Warning,
                        $mutates
                            ? 'A Redis pipeline/transaction callback mutates Redis while a database transaction is open.'
                            : 'A Redis pipeline/transaction callback cannot be proven read-only while a database transaction is open.',
                        'Keep Redis callback mutations after the database commit.', $mutates ? 'high' : 'medium');
                    $this->appendRetryFinding($findings, $offset, $tx, 'Redis callback mutation');
                }

                continue;
            }

''')

# Query mutation lists become centralized (including incrementEach/decrementEach).
replace_once('src/Analysis/SourceScanner.php',
'''        $builderMutations = 'insert|insertGetId|insertOrIgnore|insertUsing|update|updateOrInsert|upsert|delete|truncate|increment|decrement|statement|unprepared|affectingStatement';
''',
'''        $builderMutations = OperationCatalog::alternation(OperationCatalog::QUERY_MUTATIONS);
''')
replace_once('src/Analysis/SourceScanner.php',
'''        $mutations = 'insert|insertGetId|insertOrIgnore|insertUsing|update|updateOrInsert|upsert|delete|truncate|increment|decrement|statement|unprepared|affectingStatement';
''',
'''        $mutations = OperationCatalog::alternation(OperationCatalog::QUERY_MUTATIONS);
''')

# Eloquent catalogs and local setConnection overrides.
replace_once('src/Analysis/SourceScanner.php',
'''        $methods = 'create|forceCreate|updateOrCreate|firstOrCreate|upsert|insert|insertOrIgnore|update|delete|destroy|truncate|increment|decrement|forceDelete|restore';
''',
'''        $methods = OperationCatalog::alternation(OperationCatalog::ELOQUENT_STATIC_MUTATIONS);
''')
regex_once('src/Analysis/SourceScanner.php',
r'''        foreach \(\$this->matches\('/\(\?P<var>.*?save\|saveQuietly.*?decrement\)\\s\*\\\(/i'\) as \$match\) \{''',
'''        $instanceMethods = OperationCatalog::alternation(OperationCatalog::ELOQUENT_INSTANCE_MUTATIONS);
        foreach ($this->matches('/(?P<var>\\$[A-Za-z_][A-Za-z0-9_]*)\\s*->\\s*(?P<method>'.$instanceMethods.')\\s*\\(/i') as $match) {''')
replace_once('src/Analysis/SourceScanner.php',
'''            $connection = $this->classIndex->modelConnection($class) ?? $this->config->defaultDatabaseConnection;
            $this->reportCrossConnectionWrite($findings, $offset, $connection);
        }
    }

    private function eloquentConnectionFromStatement''',
'''            $connection = $this->localModelConnectionForVariable(
                $offset,
                $this->captured($match, 'var'),
                $class,
            );
            $this->reportCrossConnectionWrite($findings, $offset, $connection);
        }

        $relationMethods = OperationCatalog::alternation(OperationCatalog::RELATION_MUTATIONS);
        foreach ($this->matches('/(?P<var>\\$[A-Za-z_][A-Za-z0-9_]*)\\s*->\\s*(?P<relation>[A-Za-z_][A-Za-z0-9_]*)\\s*\\(\\s*\\)\\s*->\\s*(?P<method>'.$relationMethods.')\\s*\\(/i') as $match) {
            $offset = $match['offset'];
            if ($this->eligibleTransaction($offset) === null) {
                continue;
            }
            $class = $this->localNewClassForVariable($offset, $this->captured($match, 'var'));
            if ($class === null || ! $this->classIndex->isEloquentModel($class)) {
                continue;
            }
            $target = $this->classIndex->modelRelationTarget($class, $this->captured($match, 'relation'));
            if ($target === null) {
                continue;
            }
            $parentConnection = $this->localModelConnectionForVariable($offset, $this->captured($match, 'var'), $class);
            $connection = $this->classIndex->modelConnection($target) ?? $parentConnection;
            $this->reportCrossConnectionWrite($findings, $offset, $connection);
        }
    }

    private function localModelConnectionForVariable(int $offset, string $variable, string $class): string
    {
        $connection = $this->classIndex->modelConnection($class) ?? $this->config->defaultDatabaseConnection;
        $scope = $this->callableScopeAt($offset);
        $seen = false;

        foreach ($this->matches('/'.preg_quote($variable, '/').'\\s*->\\s*setConnection\\s*\\(/i') as $match) {
            if ($match['offset'] >= $offset || $this->callableScopeAt($match['offset']) !== $scope) {
                continue;
            }
            if ($seen || $this->conditionalControlScopeAt($match['offset']) !== null) {
                return '@dynamic';
            }
            $seen = true;
            $literal = StaticExpressionResolver::firstStringArgument(substr($this->source, $match['offset'] + strpos($match['matches'][0][0], 'setConnection')));
            $connection = $literal ?? '@dynamic';
        }

        return $connection;
    }

    private function eloquentConnectionFromStatement''')

# Driver-specific DDL policy.
regex_once('src/Analysis/SourceScanner.php',
r'''    private function implicitCommitSeverity\(array \$tx\): array\n    \{.*?\n    \}\n''',
'''    private function implicitCommitSeverity(array $tx): array
    {
        $driver = $this->config->databaseDriver($tx['connection']);
        $policy = DatabaseDriverPolicy::ddl($driver);

        return [$policy['severity'], $driver, $policy['semantics']];
    }
''')
# Call sites currently destructure two values; preserve third in context/message.
text = read('src/Analysis/SourceScanner.php')
text = text.replace('[$severity, $driver] = $this->implicitCommitSeverity($tx);', '[$severity, $driver, $semantics] = $this->implicitCommitSeverity($tx);')
text = text.replace("['database_driver' => $driver ?? 'unknown', 'database_connection' => $tx['connection']]);", "['database_driver' => $driver ?? 'unknown', 'database_connection' => $tx['connection'], 'ddl_semantics' => $semantics]);")
write('src/Analysis/SourceScanner.php', text)

# Replace literal-quoted DB statement detection with static string/heredoc/concat reduction.
regex_once('src/Analysis/SourceScanner.php',
r'''        foreach \(\$this->facadeAliases\('Illuminate\\\\Support\\\\Facades\\\\DB', 'DB'\) as \$alias\) \{\n            \$pattern = '/\(\?<!\[A-Za-z0-9_\]\)'.*?        \}\n    \}\n\n    /\*\*\n     \* @param  TransactionRegion  \$tx''',
'''        foreach ($this->facadeAliases('Illuminate\\Support\\Facades\\DB', 'DB') as $alias) {
            $pattern = '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\\s*::\\s*(?P<method>statement|unprepared)\\s*\\(/i';
            foreach ($this->matches($pattern) as $match) {
                $offset = $match['offset'];
                $tx = $this->eligibleTransaction($offset);
                if ($tx === null) {
                    continue;
                }
                $call = substr($this->source, $offset + strpos($match['matches'][0][0], $this->captured($match, 'method')));
                $sql = StaticExpressionResolver::firstStringArgument($call);
                if ($sql === null || preg_match('/^(CREATE|ALTER|DROP|TRUNCATE|RENAME|LOCK\\s+TABLES|UNLOCK\\s+TABLES|SET\\s+AUTOCOMMIT)\\b/i', ltrim($sql)) !== 1) {
                    continue;
                }
                [$severity, $driver, $semantics] = $this->implicitCommitSeverity($tx);
                $this->appendFinding($findings, $offset, 'TG012', $severity,
                    'A statically resolved SQL statement with driver-specific transaction semantics is executed inside a Laravel transaction.',
                    'Move DDL/implicit-commit statements outside normal application transactions and let migrations/schema tooling own schema changes.', 'high',
                    ['database_driver' => $driver ?? 'unknown', 'database_connection' => $tx['connection'], 'ddl_semantics' => $semantics]);
            }
        }
    }

    /**
     * @param  TransactionRegion  $tx''')

# Cache scanner facade operations from central catalog; add lock-chain analysis.
regex_once('src/Analysis/SourceScanner.php',
r'''        foreach \(\$this->facadeAliases\('Illuminate\\\\Support\\\\Facades\\\\Cache', 'Cache'\) as \$alias\) \{\n            \$pattern = '/\(\?<!\[A-Za-z0-9_\]\)'.*?        \}\n    \}\n\n    /\*\* @param list<Finding> \$findings \*/\n    private function scanRedis''',
'''        foreach ($this->facadeAliases('Illuminate\\Support\\Facades\\Cache', 'Cache') as $alias) {
            $methods = OperationCatalog::alternation(OperationCatalog::CACHE_MUTATIONS);
            $pattern = '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\\s*::(?:(?!;).)*?\\b(?P<method>'.$methods.')\\s*\\(/is';
            foreach ($this->matches($pattern) as $match) {
                $offset = $match['offset'];
                $tx = $this->eligibleTransaction($offset);
                if ($tx === null) {
                    continue;
                }
                $this->appendFinding($findings, $offset, 'TG008', Severity::Warning,
                    'Cache state is mutated before the database transaction commits.',
                    'Invalidate or mutate cache after commit so rollback cannot leave cache and database state inconsistent.', 'high');
                $this->appendRetryFinding($findings, $offset, $tx, 'cache mutation');
            }

            $lockTerminals = OperationCatalog::alternation(OperationCatalog::CACHE_LOCK_TERMINALS);
            $lockPattern = '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\\s*::\\s*(?:lock|restoreLock)\\s*\\((?:(?!;).)*?\\)\\s*->\\s*(?P<method>'.$lockTerminals.')\\s*\\(/is';
            foreach ($this->matches($lockPattern) as $match) {
                $offset = $match['offset'];
                $tx = $this->eligibleTransaction($offset);
                if ($tx === null) {
                    continue;
                }
                $this->appendFinding($findings, $offset, 'TG008', Severity::Warning,
                    'A cache lock is acquired/released while a database transaction is open.',
                    'Acquire or release distributed cache locks after commit unless their lifecycle is explicitly compensatable.', 'high');
                $this->appendRetryFinding($findings, $offset, $tx, 'cache lock mutation');
            }
        }
    }

    /** @param list<Finding> $findings */
    private function scanRateLimiter(array &$findings): void
    {
        if (! $this->sourceContainsAny(['ratelimiter', 'rate limiter'])) {
            return;
        }

        foreach ($this->facadeAliases('Illuminate\\Support\\Facades\\RateLimiter', 'RateLimiter') as $alias) {
            $methods = OperationCatalog::alternation(OperationCatalog::RATE_LIMITER_MUTATIONS);
            foreach ($this->matches('/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\\s*::\\s*(?P<method>'.$methods.')\\s*\\(/i') as $match) {
                $offset = $match['offset'];
                $tx = $this->eligibleTransaction($offset);
                if ($tx === null) {
                    continue;
                }
                $this->appendFinding($findings, $offset, 'TG008', Severity::Warning,
                    'RateLimiter mutates cache-backed rate-limit state while a database transaction is open.',
                    'Update rate-limit state after commit when it is coupled to transactional business state.', 'high');
                $this->appendRetryFinding($findings, $offset, $tx, 'rate limiter mutation');
            }
        }
    }

    /** @param list<Finding> $findings */
    private function scanRedis''')

# Redis scanner: central list, callback wrappers, and generic literal command classification.
replace_once('src/Analysis/SourceScanner.php',
'''        $mutating = 'set|setex|psetex|mset|del|unlink|incr|incrby|incrbyfloat|decr|decrby|hset|hmset|hdel|hincrby|lpush|rpush|lpop|rpop|ltrim|sadd|srem|smove|zadd|zincrby|zrem|expire|pexpire|persist|flushdb|flushall|publish|xadd|xdel|xtrim|pipeline|transaction';
''',
'''        $mutating = OperationCatalog::alternation(OperationCatalog::REDIS_MUTATIONS);
''')
regex_once('src/Analysis/SourceScanner.php',
r'''            \$commandPattern = '/\(\?<!\[A-Za-z0-9_\]\)'.*?            \}\n        \}\n    \}\n\n    /\*\* @param  list<Finding>  \$findings \*/\n    private function scanProcesses''',
'''            $commandPattern = '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\\s*::\\s*command\\s*\\(\\s*[\\'\\"](?P<command>[A-Za-z0-9_]+)[\\'\\"]/i';
            foreach ($this->matches($commandPattern, ['command']) as $match) {
                $offset = $match['offset'];
                $tx = $this->eligibleTransaction($offset);
                if ($tx === null) {
                    continue;
                }

                $command = strtoupper($this->captured($match, 'command'));
                $kind = OperationCatalog::redisCommandKind($command);
                if ($kind === 'read') {
                    continue;
                }
                $severity = $command === 'PUBLISH' ? Severity::Error : Severity::Warning;
                $this->appendFinding($findings, $offset, 'TG020', $severity,
                    $kind === 'mutation'
                        ? "Redis command {$command} mutates non-transactional state while a database transaction is open."
                        : "Redis command {$command} cannot be proven read-only while a database transaction is open.",
                    'Move Redis mutations after commit; review unknown/script commands explicitly.',
                    $kind === 'mutation' ? 'high' : 'medium');
                $this->appendRetryFinding($findings, $offset, $tx, "Redis {$command}");
            }

            foreach ($this->matches('/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\\s*::\\s*(?P<method>pipeline|transaction)\\s*\\(/i') as $match) {
                $offset = $match['offset'];
                $tx = $this->eligibleTransaction($offset);
                if ($tx === null) {
                    continue;
                }
                [$mutates, $unknown] = $this->redisCallbackMutationState($this->statementAt($offset));
                if (! $mutates && ! $unknown) {
                    continue;
                }
                $this->appendFinding($findings, $offset, 'TG020', Severity::Warning,
                    $mutates
                        ? 'A Redis pipeline/transaction callback mutates Redis while a database transaction is open.'
                        : 'A Redis pipeline/transaction callback cannot be proven read-only while a database transaction is open.',
                    'Keep Redis callback mutations after the database commit.', $mutates ? 'high' : 'medium');
                $this->appendRetryFinding($findings, $offset, $tx, 'Redis callback mutation');
            }
        }
    }

    /** @return array{bool,bool} mutates, unknown */
    private function redisCallbackMutationState(string $statement): array
    {
        $code = $this->codeOnlyFragment($statement);
        $mutations = OperationCatalog::alternation(OperationCatalog::REDIS_MUTATIONS);
        if (preg_match('/->\\s*(?:'.$mutations.')\\s*\\(/i', $code) === 1) {
            return [true, false];
        }

        $hasInlineCallable = preg_match('/(?:pipeline|transaction)\\s*\\(\\s*(?:static\\s+)?(?:function|fn)\\b/i', $code) === 1;
        return [false, ! $hasInlineCallable];
    }

    /** @param  list<Finding>  $findings */
    private function scanProcesses''')

# Variable payload support for Bus, Queue, Event and Notification facades.
insert_before('src/Analysis/SourceScanner.php',
'''    private function localNewClassForVariable(int $offset, string $variable): ?string
''',
'''    /** @param list<Finding> $findings */
    private function scanVariableFrameworkPayloads(array &$findings): void
    {
        foreach ($this->facadeAliases('Illuminate\\Support\\Facades\\Bus', 'Bus') as $alias) {
            foreach ($this->matches('/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\\s*::\\s*dispatch\\s*\\(\\s*(?P<var>\\$[A-Za-z_][A-Za-z0-9_]*)/i') as $match) {
                $this->reportVariableJobPayload($findings, $match['offset'], $this->captured($match, 'var'), false);
            }
        }
        foreach ($this->facadeAliases('Illuminate\\Support\\Facades\\Queue', 'Queue') as $alias) {
            foreach ($this->matches('/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\\s*::\\s*(?:push|later|pushOn|laterOn)\\s*\\([^;]*?(?P<var>\\$[A-Za-z_][A-Za-z0-9_]*)/i') as $match) {
                $this->reportVariableJobPayload($findings, $match['offset'], $this->captured($match, 'var'), false);
            }
        }
        foreach ($this->facadeAliases('Illuminate\\Support\\Facades\\Event', 'Event') as $alias) {
            foreach ($this->matches('/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\\s*::\\s*dispatch\\s*\\(\\s*(?P<var>\\$[A-Za-z_][A-Za-z0-9_]*)/i') as $match) {
                $offset = $match['offset'];
                $tx = $this->eligibleTransaction($offset);
                if ($tx === null) {
                    continue;
                }
                $class = $this->localNewClassForVariable($offset, $this->captured($match, 'var'));
                $metadata = $class !== null ? $this->classIndex->metadata($class) : null;
                if ($metadata?->eventAfterCommit() === true) {
                    continue;
                }
                $this->appendFinding($findings, $offset, 'TG002', Severity::Warning,
                    $class === null ? 'A variable Event::dispatch payload is not provably commit-safe.' : "Event [{$this->basename($class)}] is dispatched before commit.",
                    'Implement ShouldDispatchAfterCommit or dispatch after commit.', $metadata === null ? 'medium' : 'high');
            }
        }
        foreach ($this->facadeAliases('Illuminate\\Support\\Facades\\Notification', 'Notification') as $alias) {
            foreach ($this->matches('/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\\s*::\\s*send\\s*\\([^;]*?,\\s*(?P<var>\\$[A-Za-z_][A-Za-z0-9_]*)/is') as $match) {
                $offset = $match['offset'];
                $tx = $this->eligibleTransaction($offset);
                if ($tx === null) {
                    continue;
                }
                $class = $this->localNewClassForVariable($offset, $this->captured($match, 'var'));
                $metadata = $class !== null ? $this->classIndex->metadata($class) : null;
                if ($metadata?->queued() === true && $this->notificationDispatchIsAfterCommitSafe($this->statementAt($offset), $metadata)) {
                    continue;
                }
                $this->appendFinding($findings, $offset, 'TG004', Severity::Error,
                    $class === null ? 'A variable Notification::send payload is not provably commit-safe.' : "Notification [{$this->basename($class)}] may be delivered before commit.",
                    'Make queued delivery commit-aware or send after commit.', $metadata === null ? 'medium' : 'high');
            }
        }
    }

    /** @param list<Finding> $findings */
    private function reportVariableJobPayload(array &$findings, int $offset, string $variable, bool $pendingDispatch): void
    {
        $tx = $this->eligibleTransaction($offset);
        if ($tx === null) {
            return;
        }
        $class = $this->localNewClassForVariable($offset, $variable);
        $metadata = $class !== null ? $this->classIndex->metadata($class) : null;
        if ($pendingDispatch) {
            $this->appendPendingDispatchLifecycleFindings($findings, $offset, $tx, $metadata);
        }
        if ($metadata !== null && ! $metadata->queued()) {
            $this->appendFinding($findings, $offset, 'TG016', Severity::Warning,
                "Dispatch of non-queueable [{$this->basename($class)}] executes synchronously inside the transaction.",
                'Move synchronous work outside the transaction.', 'high');
            return;
        }
        if ($this->jobDispatchIsAfterCommitSafe($this->statementAt($offset), $metadata)) {
            return;
        }
        $this->appendFinding($findings, $offset, 'TG001', $metadata === null ? Severity::Warning : Severity::Error,
            $class === null ? 'A variable job payload cannot be proven commit-safe.' : "Job [{$this->basename($class)}] may dispatch before commit.",
            'Make the payload type statically visible and commit-aware, or dispatch after commit.', $metadata === null ? 'medium' : 'high');
    }

''')
# Invoke the new framework variable scan at end of existing variable payload scan.
replace_once('src/Analysis/SourceScanner.php',
'''        $this->scanVariablePayloadEffects($findings);
        $this->scanVariableFacadeHandles($findings);
''',
'''        $this->scanVariablePayloadEffects($findings);
        $this->scanVariableFrameworkPayloads($findings);
        $this->scanVariableFacadeHandles($findings);
''')

# Global dispatch($var) gets PendingDispatch lifecycle too.
replace_once('src/Analysis/SourceScanner.php',
'''            $class = $this->localNewClassForVariable($offset, $this->captured($match, 'var'));
            $metadata = $class !== null ? $this->classIndex->metadata($class) : null;
            $statement = $this->statementAt($offset);

            if ($metadata !== null && ! $metadata->queued()) {
''',
'''            $class = $this->localNewClassForVariable($offset, $this->captured($match, 'var'));
            $metadata = $class !== null ? $this->classIndex->metadata($class) : null;
            $statement = $this->statementAt($offset);
            $this->appendPendingDispatchLifecycleFindings($findings, $offset, $tx, $metadata);

            if ($metadata !== null && ! $metadata->queued()) {
''')

# Helpers for lifecycle and literal condition folding.
insert_before('src/Analysis/SourceScanner.php',
'''    private function jobDispatchIsAfterCommitSafe(string $statement, ?ClassMetadata $metadata): bool
''',
'''    /** @param list<Finding> $findings @param TransactionRegion $tx */
    private function appendPendingDispatchLifecycleFindings(array &$findings, int $offset, array $tx, ?ClassMetadata $metadata): void
    {
        if ($metadata === null) {
            return;
        }
        if ($metadata->preparesForDispatch()) {
            $this->appendFinding($findings, $offset, 'TG022', Severity::Warning,
                "Job [{$this->basename($metadata->name)}] implements PreparesForDispatch; prepareForDispatch() runs synchronously before Laravel can defer queueing until commit.",
                'Keep prepareForDispatch() free of irreversible work or construct/dispatch the job after commit.', 'high',
                ['transaction_type' => $tx['type']]);
        }
        if ($metadata->uniqueBeforeDispatch() || $metadata->debounced) {
            $kind = $metadata->uniqueBeforeDispatch() && $metadata->debounced ? 'unique/debounce' : ($metadata->uniqueBeforeDispatch() ? 'unique' : 'debounce');
            $this->appendFinding($findings, $offset, 'TG023', Severity::Warning,
                "PendingDispatch may acquire {$kind} cache state for [{$this->basename($metadata->name)}] before queue after-commit deferral takes effect.",
                'Dispatch after commit when pre-commit cache-lock state is not acceptable; Laravel may compensate locks on rollback but the state exists while the transaction is open.', 'high',
                ['transaction_type' => $tx['type'], 'lock_kind' => $kind]);
        }
    }

    private function conditionalDispatchIsSkipped(string $statement, string $method): bool
    {
        if (! in_array($method, ['dispatchIf', 'dispatchUnless'], true)) {
            return false;
        }
        $condition = StaticExpressionResolver::booleanFirstArgument($statement, $method);
        if ($condition === null) {
            return false;
        }

        return $method === 'dispatchIf' ? ! $condition : $condition;
    }

''')

# ---------------------------------------------------------------------------
# Tests/scenarios for the new semantics.
# ---------------------------------------------------------------------------
write('tests/Support/Scenarios/V040Hardening.php', r'''<?php

declare(strict_types=1);

return [
    'PreparesForDispatch is visible even with afterCommit' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\PreparesForDispatch;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\DB;
class PrepareOrder implements ShouldQueueAfterCommit, PreparesForDispatch { public function prepareForDispatch() {} }
DB::transaction(fn () => PrepareOrder::dispatch());
PHP,
        'rules' => ['TG022'],
        'absent' => ['TG001'],
    ],
    'ShouldBeUnique PendingDispatch exposes precommit cache lock' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\DB;
class UniqueOrder implements ShouldQueueAfterCommit, ShouldBeUnique {}
DB::transaction(fn () => UniqueOrder::dispatch());
PHP,
        'rules' => ['TG023'],
        'absent' => ['TG001'],
    ],
    'DebounceFor PendingDispatch exposes precommit cache lock' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Queue\Attributes\DebounceFor;
use Illuminate\Support\Facades\DB;
#[DebounceFor(5)]
class DebouncedOrder implements ShouldQueueAfterCommit {}
DB::transaction(fn () => DebouncedOrder::dispatch());
PHP,
        'rules' => ['TG023'],
        'absent' => ['TG001'],
    ],
    'dispatchIf false is statically skipped' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
class ProcessOrder implements ShouldQueue {}
DB::transaction(fn () => ProcessOrder::dispatchIf(false));
PHP,
        'rules' => [],
        'absent' => ['TG001'],
    ],
    'dispatchUnless true is statically skipped' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
class ProcessOrder implements ShouldQueue {}
DB::transaction(fn () => ProcessOrder::dispatchUnless(true));
PHP,
        'rules' => [],
        'absent' => ['TG001'],
    ],
    'cache lock block is a cache mutation' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
DB::transaction(fn () => Cache::lock('x')->block(5, fn () => true));
PHP,
        'rules' => ['TG008'],
    ],
    'rate limiter hit is a cache mutation' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
DB::transaction(fn () => RateLimiter::hit('login'));
PHP,
        'rules' => ['TG008'],
    ],
    'read-only Redis pipeline is not a mutation' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
DB::transaction(fn () => Redis::pipeline(fn ($pipe) => $pipe->get('x')));
PHP,
        'rules' => [],
        'absent' => ['TG020'],
    ],
    'mutating Redis pipeline is detected' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
DB::transaction(fn () => Redis::pipeline(fn ($pipe) => $pipe->set('x', 'y')));
PHP,
        'rules' => ['TG020'],
    ],
    'unknown Redis command is conservative' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
DB::transaction(fn () => Redis::command('FUTUREWRITE', ['x']));
PHP,
        'rules' => ['TG020'],
    ],
    'incrementEach cross connection is detected' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
DB::connection('mysql')->transaction(fn () => DB::connection('pgsql')->table('counters')->incrementEach(['a' => 1]));
PHP,
        'rules' => ['TG021'],
        'config' => ['database_default' => 'mysql'],
    ],
    'local model setConnection participates in TG021' => [
        'code' => <<<'PHP'
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
class Order extends Model {}
DB::connection('mysql')->transaction(function () { $order = new Order; $order->setConnection('pgsql'); $order->save(); });
PHP,
        'rules' => ['TG021'],
        'config' => ['database_default' => 'mysql'],
    ],
    'relationship target connection participates in TG021' => [
        'code' => <<<'PHP'
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
class Role extends Model { protected $connection = 'pgsql'; }
class User extends Model { public function roles() { return $this->belongsToMany(Role::class); } }
DB::connection('mysql')->transaction(function () { $user = new User; $user->roles()->attach(1); });
PHP,
        'rules' => ['TG021'],
        'config' => ['database_default' => 'mysql'],
    ],
    'Bus dispatch variable payload is detected' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
class ProcessOrder implements ShouldQueue {}
DB::transaction(function () { $job = new ProcessOrder; Bus::dispatch($job); });
PHP,
        'rules' => ['TG001'],
    ],
    'Event static dispatch uses Dispatchable trait outside Events namespace' => [
        'code' => <<<'PHP'
<?php
namespace App\Domain;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Facades\DB;
class OrderChanged { use Dispatchable; }
DB::transaction(fn () => OrderChanged::dispatch());
PHP,
        'rules' => ['TG002'],
    ],
    'heredoc DDL is detected' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
DB::transaction(function () { DB::statement(<<<'SQL'
CREATE TABLE example (id INT)
SQL); });
PHP,
        'rules' => ['TG012'],
    ],
];
''')

# Scenario matrix aggregates v0.4 module without re-expanding the legacy fixture.
text = read('tests/Support/ScenarioMatrix.php')
if "Scenarios/V040Hardening.php" not in text:
    if not text.rstrip().endswith('return $scenarios;'):
        raise RuntimeError('ScenarioMatrix return anchor not found')
    text = text.replace('return $scenarios;', "$scenarios = array_merge($scenarios, require __DIR__.'/Scenarios/V040Hardening.php');\n\nreturn $scenarios;")
    write('tests/Support/ScenarioMatrix.php', text)

write('tests/Feature/FrameworkContractTest.php', r'''<?php

declare(strict_types=1);

use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Queue\Queue;

it('tracks Laravel PendingDispatch pre-dispatch ordering', function (): void {
    $file = (new ReflectionClass(PendingDispatch::class))->getFileName();
    expect($file)->toBeString();
    $source = file_get_contents($file);
    expect($source)->toBeString();

    $prepare = strpos($source, 'prepareForDispatch()');
    $unique = strpos($source, 'UniqueLock');
    $dispatch = strpos($source, '->dispatch($this->job)');

    expect($prepare)->not->toBeFalse()
        ->and($unique)->not->toBeFalse()
        ->and($dispatch)->not->toBeFalse()
        ->and($prepare)->toBeLessThan($dispatch)
        ->and($unique)->toBeLessThan($dispatch);
});

it('tracks Laravel queue after-commit enqueue semantics', function (): void {
    $file = (new ReflectionClass(Queue::class))->getFileName();
    expect($file)->toBeString();
    $source = file_get_contents($file);
    expect($source)->toBeString()
        ->and($source)->toContain('shouldDispatchAfterCommit($job)')
        ->and($source)->toContain("addCallback(");
});
''')

# Command tests for strict TG014 and alternate PCRE delimiters.
insert_before('tests/Feature/V030HardeningTest.php',
'''it('resolves an eloquent parent through the composer loader', function (): void {
''',
'''it('can fail CI on unresolved transaction callbacks', function (): void {
    $dir = sys_get_temp_dir().'/tg-unresolved-'.bin2hex(random_bytes(4));
    mkdir($dir, 0777, true);
    $file = $dir.'/Service.php';
    file_put_contents($file, "<?php use Illuminate\\Support\\Facades\\DB; DB::transaction([new stdClass, 'run']);");
    try {
        config()->set('transaction-guard.fail_on_unresolved_transaction', true);
        $this->artisan('transaction:guard', ['paths' => [$file], '--fail-on' => 'never'])->assertExitCode(1);
    } finally {
        @unlink($file);
        @rmdir($dir);
    }
});

it('accepts alternate PCRE delimiters for custom patterns', function (): void {
    expect(fn () => new AnalysisConfig(customSideEffectPatterns: ['~SmsGateway::send\\s*\\(~i']))
        ->not->toThrow(InvalidArgumentException::class);
});

''')

# ---------------------------------------------------------------------------
# Documentation, changelog and benchmark output.
# ---------------------------------------------------------------------------
replace_once('README.md',
'''| `TG021` | error | Database/Eloquent write on another connection |
| `TG100` | warning | Configured custom side effect |
''',
'''| `TG021` | error | Database/Eloquent write on another connection |
| `TG022` | warning | `PreparesForDispatch` hook runs before commit-aware queueing |
| `TG023` | warning | Unique/debounce PendingDispatch cache state before commit |
| `TG100` | warning | Configured custom side effect |
''')
replace_once('README.md',
'''| `TG902` | error | Analyzer regex/runtime failure |
''',
'''| `TG902` | error | Analyzer regex/runtime failure |
| `TG903` | error | Source-tree traversal failure |
''')
replace_once('README.md',
'''- highly branch-dependent manual transaction flows may require review;
''',
'''- highly branch-dependent manual transaction flows may require review;
- `PreparesForDispatch`, unique jobs and Laravel 13 debounce can perform pre-dispatch work before queue after-commit deferral; Transaction Guard reports these separately;
''')

# Append rule docs for new rules.
text = read('docs/RULES.md')
if '## TG022' not in text:
    text += r'''

## TG022 — Pre-dispatch hook before commit

Laravel 13 `PendingDispatch` executes `PreparesForDispatch::prepareForDispatch()` synchronously before the job reaches the queue's after-commit deferral. Keep the hook side-effect free or create/dispatch the job after commit.

## TG023 — Queue cache lock before commit

`PendingDispatch` may acquire `ShouldBeUnique` or debounce cache state before the queue layer defers enqueueing until commit. Laravel can register rollback compensation, but external cache state still exists while the SQL transaction is open.

## TG903 — Source traversal failure

A requested source directory could not be traversed completely. Analyzer-integrity diagnostics cannot be disabled or baselined; fix permissions/exclusions before trusting the scan.
'''
    write('docs/RULES.md', text)

text = read('docs/ANALYSIS.md')
if 'Pre-dispatch lifecycle' not in text:
    text += r'''

## Pre-dispatch lifecycle

Queue `after_commit` governs queue enqueue timing; it does not retroactively defer arbitrary work performed while constructing a `PendingDispatch`. Laravel 13 can call `prepareForDispatch()`, acquire `ShouldBeUnique` locks and acquire debounce locks before the dispatcher reaches queue after-commit handling. Transaction Guard therefore reports these pre-dispatch lifecycle effects independently from TG001.

Cache locks and `RateLimiter` operations are also external cache state. Redis pipeline/transaction callbacks are inspected when inline; known read-only callbacks are ignored, mutating callbacks are reported, and unresolved callbacks remain conservative.
'''
    write('docs/ANALYSIS.md', text)

text = read('docs/DESIGN.md')
text = text.replace(
'''The analyzer builds a lightweight class metadata index for imports, inheritance, implemented interfaces, constructor `afterCommit()` / `beforeCommit()` intent, literal job queue connections, and Laravel 13 queue routing.''',
'''The analyzer builds a lightweight class metadata index for imports, inheritance, implemented interfaces, constructor `afterCommit()` / `beforeCommit()` intent, literal job queue connections, Laravel 13 queue routing, pre-dispatch attributes and simple model-relation targets.''')
if 'OperationCatalog' not in text:
    text += r'''

## Focused internal components

The tokenizer architecture remains intentionally dependency-light. `OperationCatalog` centralizes Laravel mutation APIs, `DatabaseDriverPolicy` owns driver-specific DDL classification, `StaticExpressionResolver` reduces bounded literal expressions, `MetadataAttributeResolver` resolves class attributes, and `ModelRelationExtractor` indexes simple Eloquent relation targets. These extractions keep framework-semantic tables out of the scanner hot path without introducing a general PHP AST/call-graph engine.
'''
write('docs/DESIGN.md', text)

# Changelog v0.4.0.
replace_once('CHANGELOG.md',
'''# Changelog

All notable changes to this project will be documented in this file.

''',
'''# Changelog

All notable changes to this project will be documented in this file.

## [v0.4.0] - 2026-08-22

### Added

- Laravel pre-dispatch lifecycle analysis for `PreparesForDispatch`, unique jobs and Laravel 13 debounce locks.
- Cache-lock and RateLimiter transaction-boundary analysis.
- Central operation catalogs, driver policy, bounded static-expression reduction, attribute resolution and simple Eloquent relation metadata extraction.
- Variable payload coverage for Bus, Queue, Event and Notification facade calls.
- TG903 source-tree traversal diagnostics and optional strict TG014 CI behavior.
- Framework contract tests that pin critical Laravel 12/13 queue lifecycle assumptions.

### Changed

- Redis pipelines/transactions inspect inline callback mutations instead of treating the wrapper itself as a mutation.
- Redis literal commands distinguish known reads, mutations, scripts and unknown future commands.
- TG021 covers incrementEach/decrementEach, local model setConnection overrides and statically known relation targets.
- Static event dispatch uses Dispatchable metadata instead of an `App\\Events` namespace heuristic.
- `dispatchIf(false)` and `dispatchUnless(true)` are constant-folded.
- DDL findings expose driver-specific semantics and static SQL reduction supports heredoc/concatenated literals.
- Finding deduplication includes source columns; baselines are written atomically; source indexing handles CR/LF/CRLF consistently.
- Custom side-effect patterns support validated non-slash PCRE delimiters.

''')

# Semantic docs checker validates README severity cells too.
write('tools/check-docs.php', r'''<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/src/Analysis/RuleCatalog.php';

use Codegenie\TransactionGuard\Analysis\RuleCatalog;

$rules = file_get_contents(dirname(__DIR__).'/docs/RULES.md');
$readme = file_get_contents(dirname(__DIR__).'/README.md');
if ($rules === false || $readme === false) {
    fwrite(STDERR, "Unable to read documentation.\n");
    exit(1);
}

$failed = [];
foreach (RuleCatalog::ids() as $id) {
    $definition = RuleCatalog::definition($id);
    if (! str_contains($rules, $id) && ! RuleCatalog::isDiagnostic($id)) {
        $failed[] = "docs/RULES.md is missing {$id}";
    }
    if (! str_contains($readme, $id)) {
        $failed[] = "README.md is missing {$id}";
    }
    $severity = preg_quote($definition['severity'], '/');
    if (preg_match('/\\| `'.preg_quote($id, '/').'` \\| '.$severity.' \\|/i', $readme) !== 1) {
        $failed[] = "README.md severity is out of sync for {$id}: expected {$definition['severity']}";
    }
}

if ($failed !== []) {
    fwrite(STDERR, implode("\n", $failed)."\n");
    exit(1);
}

fwrite(STDOUT, "Rule documentation is synchronized.\n");
''')

# Benchmark supports machine-readable trend capture and a mixed realistic workload.
replace_once('tools/benchmark.php',
'''$workloads = [
''',
'''$jsonOutput = in_array('--json', $argv, true);
$workloads = [
''')
replace_once('tools/benchmark.php',
'''    'metadata-heavy-250' => [250, static fn (int $file): string => "<?php\\nnamespace App\\\\Jobs; use Illuminate\\\\Contracts\\\\Queue\\\\ShouldQueue; class Base{$file} implements ShouldQueue {} class Service{$file} extends Base{$file} {}\\n"],
];
foreach ($workloads as $name => [$files, $factory]) {
    $result = benchmarkTransactionGuard($name, $files, $factory);
    printf("%s: %d files in %.2f ms; peak delta %.2f MiB; %d findings.\\n", $name, $result['files'], $result['ms'], $result['memory'], $result['findings']);
}
''',
'''    'metadata-heavy-250' => [250, static fn (int $file): string => "<?php\\nnamespace App\\\\Jobs; use Illuminate\\\\Contracts\\\\Queue\\\\ShouldQueue; class Base{$file} implements ShouldQueue {} class Service{$file} extends Base{$file} {}\\n"],
    'mixed-laravel-100' => [100, static fn (int $file): string => "<?php\\nnamespace App\\\\Services; use Illuminate\\\\Support\\\\Facades\\\\{DB,Http,Cache,Redis}; final class Service{$file} { public function run(): void { DB::transaction(function () { Http::post('https://example.test'); Cache::put('k', 1); Redis::set('k', 'v'); DB::table('orders')->update(['paid'=>true]); }); } }\\n"],
];
$results = [];
foreach ($workloads as $name => [$files, $factory]) {
    $result = benchmarkTransactionGuard($name, $files, $factory);
    $results[$name] = $result;
    if (! $jsonOutput) {
        printf("%s: %d files in %.2f ms; peak delta %.2f MiB; %d findings.\\n", $name, $result['files'], $result['ms'], $result['memory'], $result['findings']);
    }
}
if ($jsonOutput) {
    echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
}
''')

# Manual dependency-free runners load the extracted components.
for path in ['tools/smoke.php', 'tools/benchmark.php']:
    text = read(path)
    anchor = "    'src/Analysis/ClassMetadata.php',\n"
    block = "    'src/Analysis/OperationCatalog.php',\n    'src/Analysis/DatabaseDriverPolicy.php',\n    'src/Analysis/StaticExpressionResolver.php',\n    'src/Analysis/MetadataAttributeResolver.php',\n    'src/Analysis/ModelRelationExtractor.php',\n"
    if 'src/Analysis/OperationCatalog.php' not in text:
        if anchor not in text:
            raise RuntimeError(f'manual runner anchor missing in {path}')
        text = text.replace(anchor, anchor + block, 1)
        write(path, text)

# Composer archive is explicitly allowlist-oriented at release validation time;
# keep package metadata descriptive.
replace_once('composer.json',
'''        "benchmark": "Profile representative transaction-free, safe and side-effect-heavy analyzer workloads.",
''',
'''        "benchmark": "Profile representative transaction-free, safe, side-effect-heavy and mixed analyzer workloads; pass --json for trend capture.",
''')

# Remove all temporary v0.3 trigger artifacts from the release tree.
for relative in ['.v030-trigger', '.v030-trigger-2', '.v030-trigger-3', '.v030-trigger-4', '.v030-trigger-5']:
    target = ROOT / relative
    if target.exists():
        target.unlink()

# Remove the patch script itself from the resulting release tree.
Path(__file__).unlink()

print('v0.4.0 hardening patch applied')
