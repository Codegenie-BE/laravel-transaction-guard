from __future__ import annotations

import re
from pathlib import Path


def read(path: str) -> str:
    return Path(path).read_text()


def write(path: str, text: str) -> None:
    Path(path).write_text(text)


def replace_once(path: str, old: str, new: str, label: str) -> None:
    text = read(path)
    if old not in text:
        raise SystemExit(f"{label}: expected source block not found in {path}")
    write(path, text.replace(old, new, 1))


def insert_before(path: str, marker: str, addition: str, label: str) -> None:
    text = read(path)
    if marker not in text:
        raise SystemExit(f"{label}: marker not found in {path}")
    write(path, text.replace(marker, addition + marker, 1))


def append_before_final_array(path: str, addition: str) -> None:
    text = read(path)
    marker = "\n];"
    position = text.rfind(marker)
    if position < 0:
        raise SystemExit(f"final array marker not found in {path}")
    write(path, text[:position] + "\n" + addition.rstrip() + "\n" + text[position:])


def remove(path: str) -> None:
    target = Path(path)
    if target.exists():
        target.unlink()


# ---------------------------------------------------------------------------
# AnalysisConfig: normalize custom PCREs consistently and model DB drivers.
# ---------------------------------------------------------------------------
replace_once(
    "src/Analysis/AnalysisConfig.php",
    """     * @param  list<string>  $customSideEffectPatterns\n     * @param  list<string>  $disabledRules\n     */\n""",
    """     * @param  list<string>  $customSideEffectPatterns\n     * @param  list<string>  $disabledRules\n     * @param  array<string, string>  $databaseDriverByConnection\n     */\n""",
    "analysis config phpdoc",
)
replace_once(
    "src/Analysis/AnalysisConfig.php",
    """        public bool $detectReadHttpCalls = false,\n        public string $defaultDatabaseConnection = '@default',\n    ) {\n""",
    """        public bool $detectReadHttpCalls = false,\n        public string $defaultDatabaseConnection = '@default',\n        public array $databaseDriverByConnection = [],\n    ) {\n""",
    "analysis config database driver parameter",
)
replace_once(
    "src/Analysis/AnalysisConfig.php",
    """        foreach ($this->customSideEffectPatterns as $pattern) {\n            set_error_handler(static fn (): bool => true);\n            try {\n                $valid = preg_match($pattern, '') !== false;\n            } finally {\n                restore_error_handler();\n            }\n\n            if (! $valid) {\n                throw new \\InvalidArgumentException(\"Invalid custom side-effect regular expression [{$pattern}].\");\n            }\n        }\n""",
    """        foreach ($this->customSideEffectPatterns as $pattern) {\n            $regex = str_starts_with($pattern, '/') ? $pattern : '/'.str_replace('/', '\\\\/', $pattern).'/';\n            set_error_handler(static fn (): bool => true);\n            try {\n                $valid = preg_match($regex, '') !== false;\n            } finally {\n                restore_error_handler();\n            }\n\n            if (! $valid) {\n                throw new \\InvalidArgumentException(\"Invalid custom side-effect regular expression [{$pattern}].\");\n            }\n        }\n""",
    "custom regex normalization",
)
replace_once(
    "src/Analysis/AnalysisConfig.php",
    """    public function queueDispatchesAfterCommit(?string $connection = null): bool\n    {\n        $connection ??= $this->defaultQueueConnection;\n\n        return $this->queueAfterCommitByConnection[$connection] ?? false;\n    }\n}\n""",
    """    public function queueDispatchesAfterCommit(?string $connection = null): bool\n    {\n        $connection ??= $this->defaultQueueConnection;\n\n        return $this->queueAfterCommitByConnection[$connection] ?? false;\n    }\n\n    public function databaseDriver(?string $connection = null): ?string\n    {\n        $connection ??= $this->defaultDatabaseConnection;\n\n        return $this->databaseDriverByConnection[$connection] ?? null;\n    }\n}\n""",
    "analysis config database driver resolver",
)

# ---------------------------------------------------------------------------
# Class metadata: notification viaConnections + Eloquent connection metadata.
# ---------------------------------------------------------------------------
replace_once(
    "src/Analysis/ClassMetadataIndex.php",
    """    /** @var array<string, string> Queue name to forwarded connection. */\n    private array $queueForwards = [];\n""",
    """    /** @var array<string, string> Queue name to forwarded connection. */\n    private array $queueForwards = [];\n\n    /** @var array<string, array<string, string>> */\n    private array $notificationChannelConnections = [];\n\n    /** @var array<string, string> */\n    private array $modelConnections = [];\n""",
    "metadata maps",
)
insert_before(
    "src/Analysis/ClassMetadataIndex.php",
    "    public function queueRouteConnection(string $class): ?string\n    {\n",
    r'''    /** @return array<string, string> */
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

''',
    "metadata public helpers",
)
replace_once(
    "src/Analysis/ClassMetadataIndex.php",
    """            $fqcn = $context->namespace !== '' ? $context->namespace.'\\\\'.$name : $name;\n\n            $this->classes[strtolower($fqcn)] = new ClassMetadata(\n""",
    """            $fqcn = $context->namespace !== '' ? $context->namespace.'\\\\'.$name : $name;\n            $notificationConnections = $this->notificationConnectionsForClass($tokens, $openBrace + 1, $closeBrace - 1, $source);\n            if ($notificationConnections !== null) {\n                $this->notificationChannelConnections[strtolower($fqcn)] = $notificationConnections;\n            }\n            $modelConnection = $this->modelConnectionForClass($source, $tokens, $i, $openBrace + 1, $closeBrace - 1, $context);\n            if ($modelConnection !== null) {\n                $this->modelConnections[strtolower($fqcn)] = $modelConnection;\n            }\n\n            $this->classes[strtolower($fqcn)] = new ClassMetadata(\n""",
    "class metadata enrichment",
)
insert_before(
    "src/Analysis/ClassMetadataIndex.php",
    "    /**\n     * @param  list<Token>  $tokens\n     * @return array{bool,bool,string|null,string|null,bool|null,bool}\n     */\n    private function constructorQueueBehavior",
    r'''    /**
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
                return [];
            }
            $close = $this->matchingBrace($tokens, $open, $end);
            if ($close === null) {
                return [];
            }

            $bodyStart = $tokens[$open]['offset'] + 1;
            $body = substr($source, $bodyStart, max(0, $tokens[$close]['offset'] - $bodyStart));
            if (preg_match('/\breturn\s*\[(?<items>.*?)\]\s*;/s', $body, $match) !== 1) {
                return [];
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

        return $this->literalString($expression) ?? '@dynamic';
    }

''',
    "notification and model metadata helpers",
)

# ---------------------------------------------------------------------------
# Source scanner: correctness, local data flow, Eloquent, drivers and regexes.
# ---------------------------------------------------------------------------
replace_once(
    "src/Analysis/SourceScanner.php",
    """    /** @var array<int, list<string>> */\n    private array $suppressionComments = [];\n\n    private string $source = '';\n""",
    """    /** @var array<int, list<string>> */\n    private array $suppressionComments = [];\n\n    /** @var list<Finding> */\n    private array $preScanFindings = [];\n\n    private string $source = '';\n""",
    "pre-scan diagnostics property",
)
replace_once(
    "src/Analysis/SourceScanner.php",
    """        $this->facadeAliasCache = [];\n\n        $this->callables = $this->findCallableRegions();\n        $this->transactions = array_merge($this->findClosureTransactions(), $this->findManualTransactions());\n        $this->afterCommitCallbacks = $this->findAfterCommitCallbacks();\n\n        $findings = [];\n""",
    """        $this->facadeAliasCache = [];\n        $this->preScanFindings = [];\n\n        $this->callables = $this->findCallableRegions();\n        $this->transactions = array_merge($this->findClosureTransactions(), $this->findManualTransactions());\n        if ($this->transactions === []) {\n            usort($this->preScanFindings, static fn (Finding $a, Finding $b): int => [$a->line, -$a->severity->value, $a->rule] <=> [$b->line, -$b->severity->value, $b->rule]);\n\n            return $this->preScanFindings;\n        }\n        $this->afterCommitCallbacks = $this->findAfterCommitCallbacks();\n\n        $findings = $this->preScanFindings;\n""",
    "transaction-free fast path",
)
replace_once(
    "src/Analysis/SourceScanner.php",
    """        $this->scanBroadcasts($findings);\n        $this->scanVariablePayloadEffects($findings);\n        $this->scanHttp($findings);\n""",
    """        $this->scanBroadcasts($findings);\n        $this->scanVariablePayloadEffects($findings);\n        $this->scanVariableFacadeHandles($findings);\n        $this->scanHttp($findings);\n""",
    "variable facade scan order",
)
replace_once(
    "src/Analysis/SourceScanner.php",
    """        $this->scanConcurrency($findings);\n        $this->scanCrossConnectionDatabaseWrites($findings);\n        $this->scanImplicitCommits($findings);\n""",
    """        $this->scanConcurrency($findings);\n        $this->scanCrossConnectionDatabaseWrites($findings);\n        $this->scanEloquentCrossConnectionWrites($findings);\n        $this->scanImplicitCommits($findings);\n""",
    "eloquent scan order",
)

# notification viaConnections behavior
replace_once(
    "src/Analysis/SourceScanner.php",
    """                if ($queued && ! $explicitlyBeforeCommit\n                    && ($this->statementContainsAfterCommit($statement) || $metadata->queueAfterCommit() === true || $this->queueConnectionDispatchesAfterCommit($statement, $metadata))) {\n                    continue;\n                }\n\n                $this->appendFinding($findings, $offset, 'TG004', Severity::Error,\n""",
    """                if ($queued && ! $explicitlyBeforeCommit && $this->notificationDispatchIsAfterCommitSafe($statement, $metadata)) {\n                    continue;\n                }\n\n                $this->appendFinding($findings, $offset, 'TG004', Severity::Error,\n""",
    "notification safe dispatch",
)
replace_once(
    "src/Analysis/SourceScanner.php",
    """            if ($metadata?->queued() === true && $this->jobDispatchIsAfterCommitSafe($statement, $metadata)) {\n                continue;\n            }\n\n            $this->appendFinding($findings, $offset, 'TG004', Severity::Error,\n""",
    """            if ($metadata?->queued() === true && $this->notificationDispatchIsAfterCommitSafe($statement, $metadata)) {\n                continue;\n            }\n\n            $this->appendFinding($findings, $offset, 'TG004', Severity::Error,\n""",
    "variable notification safe dispatch",
)
insert_before(
    "src/Analysis/SourceScanner.php",
    "    private function queueConnectionDispatchesAfterCommit(string $statement, ?ClassMetadata $metadata = null): bool\n",
    r'''    private function notificationDispatchIsAfterCommitSafe(string $statement, ?ClassMetadata $metadata): bool
    {
        if ($this->statementContainsBeforeCommit($statement) || $metadata?->explicitlyBeforeCommit() === true) {
            return false;
        }
        if ($this->statementContainsAfterCommit($statement) || $metadata?->queueAfterCommit() === true) {
            return true;
        }
        if (! $this->queueConnectionDispatchesAfterCommit($statement, $metadata)) {
            return false;
        }
        if ($metadata === null) {
            return true;
        }

        foreach ($this->classIndex->notificationChannelConnections($metadata->name) as $connection) {
            if ($connection === '@dynamic' || ! $this->config->queueDispatchesAfterCommit($connection)) {
                return false;
            }
        }

        return true;
    }

''',
    "notification safety helper",
)

# local transaction callback variables + transparent low-confidence diagnostic
source_path = "src/Analysis/SourceScanner.php"
source = read(source_path)
start = source.index("    /** @return list<TransactionRegion> */\n    private function findClosureTransactions(): array")
end = source.index("    /** @return list<TransactionRegion> */\n    private function findManualTransactions(): array", start)
section = source[start:end]
old = """            $closure = $this->closureWithin($open + 1, $callClose - 1);\n            if ($closure === null) {\n                continue;\n            }\n\n            $attempts = $this->transactionAttempts($closure['endToken'], $callClose);\n"""
new = """            $closure = $this->closureWithin($open + 1, $callClose - 1);\n            $inlineClosure = $closure !== null;\n            if ($closure === null) {\n                $argument = $this->nextSignificantToken($open + 1);\n                if ($argument !== null && $this->tokens[$argument]['id'] === T_VARIABLE) {\n                    $closure = $this->localClosureForVariableBefore($offset, $this->tokens[$argument]['text']);\n                }\n            }\n            if ($closure === null) {\n                $this->appendFinding($this->preScanFindings, $offset, 'TG014', Severity::Info,\n                    'A database transaction callback could not be resolved statically, so its body was not analyzed as a transaction region.',\n                    'Use an inline closure or a simple local closure variable when practical, or review the callback manually.', 'low');\n                continue;\n            }\n\n            $attempts = $inlineClosure\n                ? $this->transactionAttempts($closure['endToken'], $callClose)\n                : $this->transactionAttemptsFromCall($open, $callClose);\n"""
if old not in section:
    raise SystemExit("local callback transaction block missing")
section = section.replace(old, new, 1)
write(source_path, source[:start] + section + source[end:])
insert_before(
    source_path,
    "    private function transactionAttempts(int $closureEndToken, int $callCloseToken): int\n",
    r'''    /** @return array{start:int,end:int,startToken:int,endToken:int}|null */
    private function localClosureForVariableBefore(int $offset, string $variable): ?array
    {
        $scope = $this->callableScopeAt($offset);
        $resolved = null;
        $count = count($this->tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $this->tokens[$i];
            if ($token['offset'] >= $offset || $token['id'] !== T_VARIABLE || $token['text'] !== $variable) {
                continue;
            }
            if ($this->callableScopeAt($token['offset']) !== $scope) {
                continue;
            }

            $assign = $this->nextSignificantToken($i + 1);
            if ($assign === null || $this->tokens[$assign]['text'] !== '=') {
                continue;
            }

            $value = $this->nextSignificantToken($assign + 1);
            if ($value !== null && $this->tokens[$value]['id'] === T_STATIC) {
                $value = $this->nextSignificantToken($value + 1);
            }
            if ($value === null || ! in_array($this->tokens[$value]['id'], [T_FUNCTION, T_FN], true)) {
                $resolved = null;
                continue;
            }

            if ($this->tokens[$value]['id'] === T_FUNCTION) {
                $open = $this->nextTokenText($value + 1, '{');
                $close = $open !== null ? $this->matchingToken($open, '{', '}') : null;
                $resolved = $open !== null && $close !== null ? [
                    'start' => $this->tokens[$open]['end'],
                    'end' => $this->tokens[$close]['offset'],
                    'startToken' => $open,
                    'endToken' => $close,
                ] : null;
                continue;
            }

            $arrow = $this->nextTokenText($value + 1, '=>');
            if ($arrow === null) {
                $resolved = null;
                continue;
            }
            $expressionEnd = $this->arrowExpressionEnd($arrow + 1);
            $resolved = [
                'start' => $this->tokens[$arrow]['end'],
                'end' => $expressionEnd,
                'startToken' => $arrow,
                'endToken' => $this->tokenIndexBeforeOffset($expressionEnd) ?? $arrow,
            ];
        }

        return $resolved;
    }

    private function transactionAttemptsFromCall(int $openToken, int $callCloseToken): int
    {
        $start = $this->tokens[$openToken]['end'];
        $end = $this->tokens[$callCloseToken]['offset'];
        $arguments = substr($this->source, $start, max(0, $end - $start));

        if (preg_match('/\battempts\s*:\s*(\d+)/i', $arguments, $match) === 1) {
            return max(1, (int) $match[1]);
        }
        if (preg_match('/,\s*(\d+)\s*$/s', $arguments, $match) === 1) {
            return max(1, (int) $match[1]);
        }
        if (str_contains($arguments, ',')) {
            return 0;
        }

        return 1;
    }

''',
    "local callback helpers",
)

# local facade handles
insert_before(
    source_path,
    "    /** @param  list<Finding>  $findings */\n    private function scanHttp(array &$findings): void\n",
    r'''    /** @param list<Finding> $findings */
    private function scanVariableFacadeHandles(array &$findings): void
    {
        foreach ($this->matches('/(?P<var>\$[A-Za-z_][A-Za-z0-9_]*)\s*->\s*(?P<method>[A-Za-z_][A-Za-z0-9_]*)\s*\(/i') as $match) {
            $offset = $match['offset'];
            $tx = $this->eligibleTransaction($offset);
            if ($tx === null) {
                continue;
            }

            $handle = $this->localFacadeHandleForVariable($offset, $this->captured($match, 'var'));
            if ($handle === null) {
                continue;
            }
            $method = strtolower($this->captured($match, 'method'));

            if ($handle['kind'] === 'http') {
                $read = in_array($method, ['get', 'head', 'query'], true);
                if (! $read && ! in_array($method, ['post', 'put', 'patch', 'delete', 'send'], true)) {
                    continue;
                }
                if ($read && ! $this->config->detectReadHttpCalls) {
                    continue;
                }
                $this->appendFinding($findings, $offset, 'TG006', $read ? Severity::Warning : Severity::Error,
                    'Outbound HTTP is executed through a locally assigned Laravel HTTP client while a database transaction is open.',
                    'Perform external I/O after commit or use an idempotent/outbox strategy when atomic delivery matters.', 'high');
                $this->appendRetryFinding($findings, $offset, $tx, 'HTTP request');
                continue;
            }

            if ($handle['kind'] === 'storage' && in_array($method, [
                'put', 'putfile', 'putfileas', 'writestream', 'write', 'delete', 'move', 'copy', 'append', 'prepend',
                'setvisibility', 'makedirectory', 'createdirectory', 'deletedirectory',
            ], true)) {
                $this->appendFinding($findings, $offset, 'TG007', Severity::Warning,
                    'Filesystem mutation occurs through a locally assigned Laravel filesystem handle while a database transaction is open.',
                    'Move the filesystem mutation after commit or add explicit compensation.', 'high');
                $this->appendRetryFinding($findings, $offset, $tx, 'filesystem mutation');
                continue;
            }

            if ($handle['kind'] === 'cache' && in_array($method, [
                'put', 'set', 'putmany', 'setmultiple', 'add', 'forever', 'remember', 'rememberwithwarmth', 'rememberforever',
                'sear', 'flexible', 'touch', 'forget', 'delete', 'deletemultiple', 'clear', 'flush', 'flushlocks',
                'increment', 'decrement', 'pull',
            ], true)) {
                $this->appendFinding($findings, $offset, 'TG008', Severity::Warning,
                    'Cache state is mutated through a locally assigned cache repository before the database transaction commits.',
                    'Mutate or invalidate cache after commit.', 'high');
                $this->appendRetryFinding($findings, $offset, $tx, 'cache mutation');
                continue;
            }

            if ($handle['kind'] === 'redis' && in_array($method, [
                'set', 'setex', 'psetex', 'mset', 'del', 'unlink', 'incr', 'incrby', 'incrbyfloat', 'decr', 'decrby',
                'hset', 'hmset', 'hdel', 'hincrby', 'lpush', 'rpush', 'lpop', 'rpop', 'ltrim', 'sadd', 'srem', 'smove',
                'zadd', 'zincrby', 'zrem', 'expire', 'pexpire', 'persist', 'flushdb', 'flushall', 'publish', 'xadd', 'xdel',
                'xtrim', 'pipeline', 'transaction',
            ], true)) {
                $this->appendFinding($findings, $offset, 'TG020', $method === 'publish' ? Severity::Error : Severity::Warning,
                    'Redis state is mutated through a locally assigned Redis connection while a database transaction is open.',
                    'Move the Redis mutation after commit or use an idempotent/outbox strategy.', 'high');
                $this->appendRetryFinding($findings, $offset, $tx, 'Redis mutation');
                continue;
            }

            if ($handle['kind'] === 'process' && in_array($method, ['run', 'start', 'pipe', 'pool'], true)) {
                $this->appendFinding($findings, $offset, 'TG009', Severity::Error,
                    'An external process is started through a locally assigned Laravel process handle while a database transaction is open.',
                    'Run external processes after commit.', 'high');
                $this->appendRetryFinding($findings, $offset, $tx, 'external process');
                continue;
            }

            if ($handle['kind'] === 'db' && in_array($method, [
                'insert', 'insertgetid', 'insertorignore', 'insertusing', 'update', 'updateorinsert', 'upsert', 'delete',
                'truncate', 'increment', 'decrement', 'statement', 'unprepared', 'affectingstatement',
            ], true)) {
                $this->reportCrossConnectionWrite($findings, $offset, $handle['connection'] ?? $this->config->defaultDatabaseConnection);
            }
        }
    }

    /** @return array{kind:string,connection:string|null}|null */
    private function localFacadeHandleForVariable(int $offset, string $variable): ?array
    {
        $scope = $this->callableScopeAt($offset);
        $resolved = null;
        $count = count($this->tokens);
        $facades = [
            'http' => ['Illuminate\\Support\\Facades\\Http', 'Http'],
            'storage' => ['Illuminate\\Support\\Facades\\Storage', 'Storage'],
            'cache' => ['Illuminate\\Support\\Facades\\Cache', 'Cache'],
            'redis' => ['Illuminate\\Support\\Facades\\Redis', 'Redis'],
            'process' => ['Illuminate\\Support\\Facades\\Process', 'Process'],
            'db' => ['Illuminate\\Support\\Facades\\DB', 'DB'],
        ];

        for ($i = 0; $i < $count; $i++) {
            $token = $this->tokens[$i];
            if ($token['offset'] >= $offset || $token['id'] !== T_VARIABLE || $token['text'] !== $variable) {
                continue;
            }
            if ($this->callableScopeAt($token['offset']) !== $scope) {
                continue;
            }
            $assign = $this->nextSignificantToken($i + 1);
            if ($assign === null || $this->tokens[$assign]['text'] !== '=') {
                continue;
            }

            $raw = $this->statementAt($token['offset']);
            $code = $this->codeOnlyFragment($raw);
            $resolved = null;
            foreach ($facades as $kind => [$fqcn, $fallback]) {
                foreach ($this->facadeAliases($fqcn, $fallback) as $alias) {
                    $pattern = '/^\s*'.preg_quote($variable, '/').'\s*=\s*'.preg_quote($alias, '/').'\s*::/i';
                    if (preg_match($pattern, $code) !== 1) {
                        continue;
                    }

                    $connection = null;
                    if ($kind === 'db') {
                        $connection = $this->config->defaultDatabaseConnection;
                        if (preg_match('/::\s*connection\s*\(/i', $code, $call, PREG_OFFSET_CAPTURE) === 1) {
                            $connection = $this->literalStringArgumentFromCall(substr($raw, $call[0][1])) ?? '@dynamic';
                        }
                    }
                    $resolved = ['kind' => $kind, 'connection' => $connection];
                    break 2;
                }
            }
        }

        return $resolved;
    }

    private function localDatabaseConnectionForVariable(int $offset, string $variable): ?string
    {
        $handle = $this->localFacadeHandleForVariable($offset, $variable);

        return $handle !== null && $handle['kind'] === 'db' ? ($handle['connection'] ?? $this->config->defaultDatabaseConnection) : null;
    }

''',
    "local facade handle scanner",
)

# DB transaction control through locally assigned DB connections/builders.
source = read(source_path)
start = source.index("    /** @return list<array{offset:int,connection:string}> */\n    private function dbTransactionCalls(string $method): array")
end = source.index("    private function connectionFromExpression", start)
section = source[start:end]
needle = """        usort($calls, static fn (array $a, array $b): int => $a['offset'] <=> $b['offset']);\n"""
addition = r'''        foreach ($this->matches('/(?P<var>\$[A-Za-z_][A-Za-z0-9_]*)\s*->\s*'.preg_quote($method, '/').'\s*\(/i') as $match) {
            $connection = $this->localDatabaseConnectionForVariable($match['offset'], $this->captured($match, 'var'));
            if ($connection === null) {
                continue;
            }
            $calls[] = [
                'offset' => $match['offset'],
                'connection' => $connection,
            ];
        }

'''
if needle not in section:
    raise SystemExit("dbTransactionCalls sort marker missing")
section = section.replace(needle, addition + needle, 1)
write(source_path, source[:start] + section + source[end:])

# Centralized facade regex + fix TG018 escaping.
insert_before(
    source_path,
    "    /** @return list<string> */\n    private function facadeAliases(string $fqcn, string $fallback): array\n",
    r'''    private function facadeStaticMethodPattern(string $alias, string $methods): string
    {
        return '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::\s*(?P<method>'.$methods.')\s*\(/i';
    }

''',
    "facade regex helper",
)
source = read(source_path)
start = source.index("    /** @param list<Finding> $findings */\n    private function scanConcurrency")
end = source.index("    /** @param  list<Finding>  $findings */\n    private function scanCrossConnectionDatabaseWrites", start)
new_concurrency = r'''    /** @param list<Finding> $findings */
    private function scanConcurrency(array &$findings): void
    {
        if (! $this->sourceContainsAny(['concurrency', 'defer('])) {
            return;
        }
        foreach ($this->facadeAliases('Illuminate\\Support\\Facades\\Concurrency', 'Concurrency') as $alias) {
            foreach ($this->matches($this->facadeStaticMethodPattern($alias, 'run|defer')) as $match) {
                $offset = $match['offset'];
                $tx = $this->eligibleTransaction($offset);
                if ($tx === null) {
                    continue;
                }
                $method = strtolower($this->captured($match, 'method'));
                $this->appendFinding($findings, $offset, 'TG018', Severity::Warning,
                    "Concurrency::{$method}() is invoked while a database transaction is open; child/deferred work is not part of that transaction boundary.",
                    'Move concurrent/deferred work after commit or register it from DB::afterCommit().', 'high');
                $this->appendRetryFinding($findings, $offset, $tx, "concurrency {$method}");
            }
        }

        foreach ($this->matches('/(?<![A-Za-z0-9_])defer\s*\(/i') as $match) {
            $offset = $match['offset'];
            $tx = $this->eligibleTransaction($offset);
            if ($tx === null) {
                continue;
            }
            $this->appendFinding($findings, $offset, 'TG018', Severity::Warning,
                'defer() schedules work after the response, not after a successful database commit.',
                'Register deferred work from DB::afterCommit() when it depends on committed state.', 'medium');
            $this->appendRetryFinding($findings, $offset, $tx, 'deferred callback');
        }
    }

'''
write(source_path, source[:start] + new_concurrency + source[end:])

# Eloquent cross-connection writes.
insert_before(
    source_path,
    "    /** @param list<Finding> $findings */\n    private function scanImplicitCommits(array &$findings): void\n",
    r'''    /** @param list<Finding> $findings */
    private function scanEloquentCrossConnectionWrites(array &$findings): void
    {
        $methods = 'create|forceCreate|updateOrCreate|firstOrCreate|upsert|insert|insertOrIgnore|update|delete|destroy|truncate|increment|decrement';
        foreach ($this->matches('/(?<![A-Za-z0-9_\\\\])(?P<class>\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*)\s*::(?:(?![;{}]).)*?\b(?P<method>'.$methods.')\s*\(/is') as $match) {
            $offset = $match['offset'];
            if ($this->eligibleTransaction($offset) === null) {
                continue;
            }
            $class = $this->context->resolve($this->captured($match, 'class'));
            if (! $this->classIndex->isEloquentModel($class)) {
                continue;
            }
            $connection = $this->eloquentConnectionFromStatement($this->statementAt($offset), $class);
            $this->reportCrossConnectionWrite($findings, $offset, $connection);
        }

        foreach ($this->matches('/(?P<var>\$[A-Za-z_][A-Za-z0-9_]*)\s*->\s*(?P<method>save|delete|increment|decrement)\s*\(/i') as $match) {
            $offset = $match['offset'];
            if ($this->eligibleTransaction($offset) === null) {
                continue;
            }
            $class = $this->localNewClassForVariable($offset, $this->captured($match, 'var'));
            if ($class === null || ! $this->classIndex->isEloquentModel($class)) {
                continue;
            }
            $connection = $this->classIndex->modelConnection($class) ?? $this->config->defaultDatabaseConnection;
            $this->reportCrossConnectionWrite($findings, $offset, $connection);
        }
    }

    private function eloquentConnectionFromStatement(string $statement, string $class): string
    {
        $code = $this->codeOnlyFragment($statement);
        if (preg_match('/->\s*on\s*\(/i', $code, $call, PREG_OFFSET_CAPTURE) === 1) {
            return $this->literalStringArgumentFromCall(substr($statement, $call[0][1])) ?? '@dynamic';
        }

        return $this->classIndex->modelConnection($class) ?? $this->config->defaultDatabaseConnection;
    }

''',
    "eloquent cross-connection scanner",
)

# Driver-aware DDL severity.
source = read(source_path)
start = source.index("    /** @param list<Finding> $findings */\n    private function scanImplicitCommits")
end = source.index("    /** @param list<Finding> $findings */\n    private function scanCustomPatterns", start)
section = source[start:end]
section = section.replace(
    """                $offset = $match['offset'];\n                if ($this->eligibleTransaction($offset) === null) {\n                    continue;\n                }\n                $this->appendFinding($findings, $offset, 'TG012', Severity::Critical,\n                    'Schema/DDL work inside a transaction can trigger an implicit database commit and leave Laravel transaction state out of sync.',\n                    'Perform schema changes outside application transactions. Never rely on a surrounding transaction to roll back DDL.', 'high');\n""",
    """                $offset = $match['offset'];\n                $tx = $this->eligibleTransaction($offset);\n                if ($tx === null) {\n                    continue;\n                }\n                [$severity, $driver] = $this->implicitCommitSeverity($tx);\n                $this->appendFinding($findings, $offset, 'TG012', $severity,\n                    'Schema/DDL work is executed inside an application transaction; implicit-commit behavior depends on the active database driver.',\n                    'Keep schema changes in migrations or explicit schema-management flows outside normal application transactions.', 'high',\n                    ['database_driver' => $driver ?? 'unknown', 'database_connection' => $tx['connection']]);\n""",
    1,
)
section = section.replace(
    """                $offset = $match['offset'];\n                if ($this->eligibleTransaction($offset) === null) {\n                    continue;\n                }\n                $sql = ltrim($this->captured($match, 'sql'));\n""",
    """                $offset = $match['offset'];\n                $tx = $this->eligibleTransaction($offset);\n                if ($tx === null) {\n                    continue;\n                }\n                $sql = ltrim($this->captured($match, 'sql'));\n""",
    1,
)
section = section.replace(
    """                $this->appendFinding($findings, $offset, 'TG012', Severity::Critical,\n                    'A SQL statement that may implicitly commit is executed inside a Laravel transaction.',\n                    'Move implicit-commit DDL outside the transaction and let migrations/schema tooling own schema changes.', 'high');\n""",
    """                [$severity, $driver] = $this->implicitCommitSeverity($tx);\n                $this->appendFinding($findings, $offset, 'TG012', $severity,\n                    'A SQL statement with database-specific transaction semantics is executed inside a Laravel transaction.',\n                    'Move DDL/implicit-commit statements outside normal application transactions and let migrations/schema tooling own schema changes.', 'high',\n                    ['database_driver' => $driver ?? 'unknown', 'database_connection' => $tx['connection']]);\n""",
    1,
)
helper = r'''    /**
     * @param TransactionRegion $tx
     * @return array{Severity,string|null}
     */
    private function implicitCommitSeverity(array $tx): array
    {
        $driver = $this->config->databaseDriver($tx['connection']);
        if ($driver === null) {
            return [Severity::Critical, null];
        }

        return in_array(strtolower($driver), ['mysql', 'mariadb'], true)
            ? [Severity::Critical, $driver]
            : [Severity::Warning, $driver];
    }

'''
write(source_path, source[:start] + section + helper + source[end:])

# ---------------------------------------------------------------------------
# Console: DB driver map, SARIF and invalid UTF-8 tolerant JSON.
# ---------------------------------------------------------------------------
replace_once(
    "src/Console/TransactionGuardCommand.php",
    "{--format=console : Output format: console, json, github}",
    "{--format=console : Output format: console, json, github, sarif}",
    "command format help",
)
replace_once(
    "src/Console/TransactionGuardCommand.php",
    """        if (! in_array($format, ['console', 'json', 'github'], true)) {\n            $this->error('Invalid --format. Use console, json, or github.');\n""",
    """        if (! in_array($format, ['console', 'json', 'github', 'sarif'], true)) {\n            $this->error('Invalid --format. Use console, json, github, or sarif.');\n""",
    "command format validation",
)
replace_once(
    "src/Console/TransactionGuardCommand.php",
    """                detectReadHttpCalls: (bool) config('transaction-guard.detect_read_http_calls', false),\n                defaultDatabaseConnection: $this->stringConfig('database.default', '@default'),\n            );\n""",
    """                detectReadHttpCalls: (bool) config('transaction-guard.detect_read_http_calls', false),\n                defaultDatabaseConnection: $this->stringConfig('database.default', '@default'),\n                databaseDriverByConnection: $this->databaseDriverMap(),\n            );\n""",
    "command database driver config",
)
insert_before(
    "src/Console/TransactionGuardCommand.php",
    "    /** @param  list<Finding>  $findings */\n    private function render(string $format, array $findings, int $filesAnalyzed): void\n",
    r'''    /** @return array<string, string> */
    private function databaseDriverMap(): array
    {
        $configuredConnections = config('database.connections', []);
        if (! is_array($configuredConnections)) {
            return [];
        }

        $drivers = [];
        foreach ($configuredConnections as $name => $configuration) {
            if (! is_array($configuration) || ! isset($configuration['driver']) || ! is_string($configuration['driver'])) {
                continue;
            }
            $drivers[(string) $name] = $configuration['driver'];
        }

        return $drivers;
    }

''',
    "database driver map",
)
replace_once(
    "src/Console/TransactionGuardCommand.php",
    """            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));\n\n            return;\n        }\n\n        if ($format === 'github') {\n""",
    """            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));\n\n            return;\n        }\n\n        if ($format === 'sarif') {\n            $this->line(json_encode($this->sarifPayload($findings), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));\n\n            return;\n        }\n\n        if ($format === 'github') {\n""",
    "json and sarif rendering",
)
insert_before(
    "src/Console/TransactionGuardCommand.php",
    "    /** @return list<string> */\n    private function stringArguments(string $name): array\n",
    r'''    /**
     * @param list<Finding> $findings
     * @return array<string, mixed>
     */
    private function sarifPayload(array $findings): array
    {
        $rules = [];
        $results = [];

        foreach ($findings as $finding) {
            $rules[$finding->rule] ??= [
                'id' => $finding->rule,
                'name' => $finding->rule,
                'shortDescription' => ['text' => $finding->message],
                'help' => ['text' => $finding->remediation],
            ];
            $results[] = [
                'ruleId' => $finding->rule,
                'level' => $this->sarifLevel($finding->severity),
                'message' => ['text' => $finding->message],
                'locations' => [[
                    'physicalLocation' => [
                        'artifactLocation' => ['uri' => $this->relativePath($finding->file)],
                        'region' => ['startLine' => $finding->line],
                    ],
                ]],
                'partialFingerprints' => ['transactionGuardFingerprint' => $finding->fingerprint()],
                'properties' => [
                    'severity' => $finding->severity->label(),
                    'confidence' => $finding->confidence,
                    'context' => $finding->context,
                ],
            ];
        }

        return [
            '$schema' => 'https://json.schemastore.org/sarif-2.1.0.json',
            'version' => '2.1.0',
            'runs' => [[
                'tool' => ['driver' => [
                    'name' => 'Laravel Transaction Guard',
                    'informationUri' => 'https://github.com/Codegenie-BE/laravel-transaction-guard',
                    'rules' => array_values($rules),
                ]],
                'results' => $results,
            ]],
        ];
    }

    private function sarifLevel(Severity $severity): string
    {
        return match ($severity) {
            Severity::Critical, Severity::Error => 'error',
            Severity::Warning => 'warning',
            Severity::Info => 'note',
        };
    }

''',
    "sarif payload helpers",
)

# ---------------------------------------------------------------------------
# Tests and smoke configuration.
# ---------------------------------------------------------------------------
for path in ["tests/Unit/SourceScannerTest.php", "tools/smoke.php"]:
    replace_once(
        path,
        """        detectReadHttpCalls: (bool) ($cfg['detect_read_http_calls'] ?? false),\n        defaultDatabaseConnection: (string) ($cfg['database_default'] ?? '@default'),\n    );\n""",
        """        detectReadHttpCalls: (bool) ($cfg['detect_read_http_calls'] ?? false),\n        defaultDatabaseConnection: (string) ($cfg['database_default'] ?? '@default'),\n        databaseDriverByConnection: (array) ($cfg['database_drivers'] ?? []),\n    );\n""",
        f"database driver scenario config in {path}",
    )

append_before_final_array(
    "tests/Support/ScenarioMatrix.php",
    r'''    'local transaction callback variable is analyzed' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
$callback = function () { Http::post('https://example.test/capture'); };
DB::transaction($callback);
PHP,
        'rules' => ['TG006'],
    ],
    'reassigned transaction callback is not falsely trusted' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
$callback = function () { Http::post('https://example.test/capture'); };
$callback = resolve_callback();
DB::transaction($callback);
PHP,
        'rules' => ['TG014'],
        'absent' => ['TG006'],
    ],
    'local DB connection handle transaction is analyzed' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
$db = DB::connection('mysql');
$db->transaction(function () { Http::post('https://example.test/capture'); });
PHP,
        'rules' => ['TG006'],
        'config' => ['database_default' => 'pgsql'],
    ],
    'local HTTP client handle is detected' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
DB::transaction(function () { $http = Http::withToken('secret'); $http->post('https://example.test/capture'); });
PHP,
        'rules' => ['TG006'],
    ],
    'local Storage disk handle is detected' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
DB::transaction(function () { $disk = Storage::disk('s3'); $disk->put('receipt.txt', 'x'); });
PHP,
        'rules' => ['TG007'],
    ],
    'local Redis connection handle is detected' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
DB::transaction(function () { $redis = Redis::connection('cache'); $redis->publish('orders', 'paid'); });
PHP,
        'rules' => ['TG020'],
    ],
    'local DB handle cross connection write is detected' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
DB::connection('mysql')->transaction(function () { $audit = DB::connection('pgsql'); $audit->table('audit')->insert(['ok' => 1]); });
PHP,
        'rules' => ['TG021'],
        'config' => ['database_default' => 'mysql'],
    ],
    'Eloquent Connection attribute cross connection write is detected' => [
        'code' => <<<'PHP'
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Attributes\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
#[Connection('pgsql')]
class Audit extends Model {}
DB::connection('mysql')->transaction(function () { Audit::create(['ok' => true]); });
PHP,
        'rules' => ['TG021'],
        'config' => ['database_default' => 'mysql'],
    ],
    'same connection Eloquent write remains atomic' => [
        'code' => <<<'PHP'
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Attributes\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
#[Connection('mysql')]
class Audit extends Model {}
DB::connection('mysql')->transaction(function () { Audit::create(['ok' => true]); });
PHP,
        'rules' => [],
        'absent' => ['TG021'],
        'config' => ['database_default' => 'mysql'],
    ],
    'Eloquent model instance save honors model connection' => [
        'code' => <<<'PHP'
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
class Audit extends Model { protected $connection = 'pgsql'; }
DB::connection('mysql')->transaction(function () { $audit = new Audit(); $audit->save(); });
PHP,
        'rules' => ['TG021'],
        'config' => ['database_default' => 'mysql'],
    ],
    'notification viaConnections unsafe override defeats safe default' => [
        'code' => <<<'PHP'
<?php
namespace App\Notifications;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
class ReceiptReady implements ShouldQueue { public function viaConnections(): array { return ['mail' => 'database']; } }
DB::transaction(function () { $user->notify(new ReceiptReady()); });
PHP,
        'rules' => ['TG004'],
        'config' => ['queue_default' => 'redis', 'queue_after_commit' => ['redis' => true, 'database' => false]],
    ],
    'notification viaConnections safe overrides preserve safe base' => [
        'code' => <<<'PHP'
<?php
namespace App\Notifications;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
class ReceiptReady implements ShouldQueue { public function viaConnections(): array { return ['mail' => 'redis', 'database' => 'redis']; } }
DB::transaction(function () { $user->notify(new ReceiptReady()); });
PHP,
        'rules' => [],
        'absent' => ['TG004'],
        'config' => ['queue_default' => 'redis', 'queue_after_commit' => ['redis' => true]],
    ],
    'notification dynamic viaConnections is not trusted' => [
        'code' => <<<'PHP'
<?php
namespace App\Notifications;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
class ReceiptReady implements ShouldQueue { public const CONNECTION = 'redis'; public function viaConnections(): array { return ['mail' => self::CONNECTION]; } }
DB::transaction(function () { $user->notify(new ReceiptReady()); });
PHP,
        'rules' => ['TG004'],
        'config' => ['queue_default' => 'redis', 'queue_after_commit' => ['redis' => true]],
    ],
    'custom side effect pattern may omit delimiters' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
DB::transaction(function () { SmsGateway::send('hello'); });
PHP,
        'rules' => ['TG100'],
        'config' => ['custom_side_effect_patterns' => ['SmsGateway::send\\s*\\(']],
    ],
    'PostgreSQL DDL remains visible but is not classified as MySQL critical' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
DB::connection('pgsql')->transaction(function () { DB::statement('CREATE TABLE example (id INT)'); });
PHP,
        'rules' => ['TG012'],
        'config' => ['database_default' => 'pgsql', 'database_drivers' => ['pgsql' => 'pgsql']],
    ],'''
)

# Add explicit driver severity regression.
insert_before(
    "tests/Unit/SourceScannerTest.php",
    "it('classifies DDL and unclosed manual transactions as critical', function (): void {\n",
    r'''it('uses database-driver-aware implicit commit severity', function (): void {
    $findings = scanTransactionGuardScenario([
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
DB::connection('pgsql')->transaction(fn () => DB::statement('CREATE TABLE example (id INT)'));
PHP,
        'rules' => ['TG012'],
        'config' => [
            'database_default' => 'pgsql',
            'database_drivers' => ['pgsql' => 'pgsql'],
        ],
    ]);

    $ddl = collect($findings)->firstWhere('rule', 'TG012');
    expect($ddl)->not->toBeNull()
        ->and($ddl->severity->label())->toBe('warning');
});

''',
    "driver severity test",
)

# Command SARIF and invalid UTF-8 regressions.
append_before_final_array(
    "tests/Feature/CommandTest.php",
    r'''it('emits SARIF 2.1.0 output', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'tg-sarif-').'.php';
    file_put_contents($file, "<?php\nuse Illuminate\\Support\\Facades\\DB;\nuse Illuminate\\Support\\Facades\\Http;\nDB::transaction(fn () => Http::post('https://example.test'));\n");

    try {
        $this->artisan('transaction:guard', [
            'paths' => [$file],
            '--format' => 'sarif',
            '--fail-on' => 'never',
        ])->expectsOutputToContain('"version": "2.1.0"')
            ->expectsOutputToContain('"ruleId": "TG006"')
            ->assertSuccessful();
    } finally {
        @unlink($file);
    }
});

it('keeps JSON output valid for invalid UTF-8 source snippets', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'tg-utf8-').'.php';
    file_put_contents($file, "<?php\nuse Illuminate\\Support\\Facades\\DB;\nuse Illuminate\\Support\\Facades\\Http;\nDB::transaction(function () { /* ".chr(0xB1)." */ Http::post('https://example.test'); });\n");

    try {
        $this->artisan('transaction:guard', [
            'paths' => [$file],
            '--format' => 'json',
            '--fail-on' => 'never',
        ])->expectsOutputToContain('TG006')
            ->assertSuccessful();
    } finally {
        @unlink($file);
    }
});'''
)

# ---------------------------------------------------------------------------
# Benchmark: representative transaction-free, safe and side-effect workloads.
# ---------------------------------------------------------------------------
write(
    "tools/benchmark.php",
    r'''<?php

declare(strict_types=1);

$root = dirname(__DIR__);
foreach ([
    'src/Analysis/Severity.php',
    'src/Analysis/Finding.php',
    'src/Analysis/AnalysisConfig.php',
    'src/Analysis/ClassMetadata.php',
    'src/Analysis/FileContext.php',
    'src/Analysis/ClassMetadataIndex.php',
    'src/Analysis/SourceIndex.php',
    'src/Analysis/SourceScanner.php',
    'src/Analysis/AnalysisResult.php',
    'src/Analysis/Baseline.php',
    'src/TransactionGuard.php',
] as $file) {
    require_once $root.'/'.$file;
}

use Codegenie\TransactionGuard\TransactionGuard;

/** @return array{files:int,ms:float,memory:float,findings:int} */
function benchmarkTransactionGuard(string $name, callable $sourceFactory): array
{
    $directory = sys_get_temp_dir().'/transaction-guard-benchmark-'.$name.'-'.bin2hex(random_bytes(4));
    mkdir($directory, 0777, true);

    try {
        for ($file = 0; $file < 50; $file++) {
            file_put_contents($directory."/Service{$file}.php", $sourceFactory($file));
        }

        $startMemory = memory_get_usage(true);
        $start = hrtime(true);
        $result = (new TransactionGuard)->analyze([$directory]);

        return [
            'files' => $result->filesAnalyzed,
            'ms' => (hrtime(true) - $start) / 1_000_000,
            'memory' => (memory_get_peak_usage(true) - $startMemory) / 1024 / 1024,
            'findings' => count($result->findings),
        ];
    } finally {
        foreach (glob($directory.'/*.php') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    }
}

$workloads = [
    'transaction-free' => static fn (int $file): string => "<?php\nnamespace App\\Services;\nfinal class Service{$file} { public function run(): int { return 1; } }\n",
    'safe-transactions' => static fn (int $file): string => "<?php\nnamespace App\\Services;\nuse Illuminate\\Support\\Facades\\DB;\nfinal class Service{$file} { public function run(): void { DB::transaction(fn () => DB::table('orders')->update(['paid' => true])); } }\n",
    'side-effect-heavy' => static fn (int $file): string => "<?php\nnamespace App\\Services;\nuse Illuminate\\Support\\Facades\\DB;\nuse Illuminate\\Support\\Facades\\Http;\nfinal class Service{$file} { public function run(): void { DB::transaction(fn () => Http::post('https://example.test')); } }\n",
];

foreach ($workloads as $name => $factory) {
    $result = benchmarkTransactionGuard($name, $factory);
    printf(
        "%s: %d files in %.2f ms; peak delta %.2f MiB; %d findings.\n",
        $name,
        $result['files'],
        $result['ms'],
        $result['memory'],
        $result['findings'],
    );
}
'''
)

# ---------------------------------------------------------------------------
# CI: remove self-mutating audit job, add prefer-lowest and pre-release archive.
# ---------------------------------------------------------------------------
workflow = read(".github/workflows/tests.yml")
workflow = re.sub(r"\n  audit-writer:\n.*?\n  quality:\n", "\n  quality:\n", workflow, count=1, flags=re.S)
if "audit-writer:" in workflow:
    raise SystemExit("audit-writer job removal failed")
lowest_job = r'''
  lowest:
    name: Lowest supported dependencies
    runs-on: ubuntu-latest
    timeout-minutes: 20

    steps:
      - name: Checkout
        uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1

      - name: Setup PHP
        uses: shivammathur/setup-php@f3e473d116dcccaddc5834248c87452386958240 # v2
        with:
          php-version: '8.2'
          coverage: none
          tools: composer:v2

      - name: Pin lowest supported Laravel 12 test dependencies
        run: composer require --dev 'orchestra/testbench:^10.0' 'pestphp/pest:^3.0' --no-update --no-interaction

      - name: Resolve lowest supported dependency set
        run: composer update --prefer-lowest --prefer-stable --with-all-dependencies --no-interaction --no-progress

      - name: Run complete quality gate
        run: composer check:all

'''
marker = "  coverage:\n"
if marker not in workflow:
    raise SystemExit("coverage job marker missing")
workflow = workflow.replace(marker, lowest_job + marker, 1)
workflow = workflow.replace(
    """    needs:\n      - quality\n      - coverage\n""",
    """    needs:\n      - quality\n      - lowest\n      - coverage\n""",
    1,
)
release_checkout = """      - name: Checkout validated main\n        uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1\n        with:\n          fetch-depth: 0\n\n"""
preflight = release_checkout + """      - name: Setup PHP and Composer\n        uses: shivammathur/setup-php@f3e473d116dcccaddc5834248c87452386958240 # v2\n        with:\n          php-version: '8.5'\n          coverage: none\n          tools: composer:v2\n\n      - name: Validate release distribution before tagging\n        shell: bash\n        run: |\n          composer validate --strict\n          rm -rf build\n          composer archive --format=zip --dir=build\n          archive=\"$(find build -maxdepth 1 -type f -name '*.zip' | head -n 1)\"\n          test -n \"${archive}\"\n          unzip -l \"${archive}\" > /tmp/transaction-guard-archive.txt\n          if grep -E '(^|/)(tests|tools|docs|\\.github|\\.audit)/' /tmp/transaction-guard-archive.txt; then\n            echo 'Release archive contains development-only files.' >&2\n            exit 1\n          fi\n\n"""
if release_checkout not in workflow:
    raise SystemExit("release checkout block missing")
workflow = workflow.replace(release_checkout, preflight, 1)
write(".github/workflows/tests.yml", workflow)

# ---------------------------------------------------------------------------
# Documentation and release notes.
# ---------------------------------------------------------------------------
replace_once(
    "CHANGELOG.md",
    "## [Unreleased]\n\n### Added\n",
    """## [Unreleased]\n\n## [v0.2.0] - 2026-08-22\n\n### Added\n\n- Local closure-variable transaction callback analysis with explicit low-confidence `TG014` diagnostics when a callback cannot be resolved.\n- Conservative local Laravel handle inference for HTTP, filesystem, cache, Redis, process and database connection objects.\n- Eloquent cross-connection write analysis for statically known model connections, including Laravel 13 `#[Connection]`.\n- Notification `viaConnections()` queue-connection analysis.\n- SARIF 2.1.0 output for code-scanning integrations.\n- Database-driver-aware `TG012` severity and a lowest-supported-dependency CI job.\n""",
    "v0.2.0 changelog header",
)
replace_once(
    "CHANGELOG.md",
    "### Changed\n\n",
    """### Changed\n\n- Fixed Laravel concurrency/defer detection by centralizing facade static-method regex construction.\n- Transaction-free files now stop after transaction discovery instead of running every rule family.\n- Custom side-effect patterns are normalized consistently whether or not callers provide regex delimiters.\n- JSON and SARIF rendering substitute invalid UTF-8 source bytes instead of crashing output generation.\n- Release archives are validated before a tag is created, and analyzer benchmarks cover transaction-free, safe-transaction and side-effect-heavy workloads.\n- Temporary self-mutating audit/maintenance workflows are removed after the v0.2 hardening cycle.\n\n""",
    "v0.2.0 changed notes",
)

# README: capabilities, SARIF, rules and current limitations.
readme = read("README.md")
readme = readme.replace(
    "- literal Laravel 13 `Queue::route()` class/parent/interface connection routing;",
    "- Laravel 13 `Queue::route()` class/parent/interface/trait routing, `Queue::forward()` and statically resolvable queue attributes;",
    1,
)
readme = readme.replace(
    "php artisan transaction:guard --format=json\nphp artisan transaction:guard --fail-on=error",
    "php artisan transaction:guard --format=json\nphp artisan transaction:guard --format=sarif\nphp artisan transaction:guard --fail-on=error",
    1,
)
readme = readme.replace(
    "| `TG013` | critical | Unclosed manual transaction |\n| `TG016` | warning | Synchronous job dispatch inside transaction |",
    "| `TG013` | critical | Unclosed manual transaction |\n| `TG014` | info | Transaction callback could not be resolved statically |\n| `TG016` | warning | Synchronous job dispatch inside transaction |",
    1,
)
readme = readme.replace(
    "| `TG020` | warning / error | Redis mutation or publish inside transaction |\n| `TG100` | warning | Configured custom side effect |",
    "| `TG020` | warning / error | Redis mutation or publish inside transaction |\n| `TG021` | error | Database/Eloquent write on another connection |\n| `TG100` | warning | Configured custom side effect |",
    1,
)
old_limits = """- side effects hidden behind arbitrary application service methods require a custom pattern;\n- dynamically chosen queue connection names cannot always be resolved;\n- Laravel 13 trait-based and array-form `Queue::route()`, `Queue::forward()`, queue attributes/enums, and runtime queue reconfiguration are not trusted as proof of a safe connection in v0.1;\n- side effects hidden in arbitrary event listeners or Eloquent observers require explicit post-commit contracts or project-specific patterns;\n- highly branch-dependent manual transaction flows may require review;\n- third-party SDK calls are not guessed automatically;\n- nested closures that are merely defined inside a transaction are ignored unless immediately invoked.\n"""
new_limits = """- side effects hidden behind arbitrary application service methods require a custom pattern;\n- dynamically chosen queue/database connection names cannot always be resolved;\n- runtime queue reconfiguration, dynamic attributes/enums and arbitrary container bindings remain conservative;\n- side effects hidden in arbitrary event listeners or Eloquent observers require explicit post-commit contracts or project-specific patterns;\n- local closure variables and simple local Laravel handles are resolved, but the package intentionally does not build a general PHP call graph;\n- highly branch-dependent manual transaction flows may require review;\n- third-party SDK calls are not guessed automatically;\n- nested closures that are merely defined inside a transaction are ignored unless immediately invoked.\n"""
if old_limits not in readme:
    raise SystemExit("README limitation block missing")
readme = readme.replace(old_limits, new_limits, 1)
write("README.md", readme)

# Rule docs.
rules = read("docs/RULES.md")
marker = "## TG016 — synchronous job dispatch\n"
addition = """## TG014 — unresolved transaction callback\n\nReports an informational, low-confidence diagnostic when a `DB::transaction(...)` callback cannot be resolved as an inline closure or a simple local closure variable. The guard does not pretend that an unanalyzed callback is proven safe.\n\nPrefer an inline closure or simple local closure variable when practical; otherwise review the callback manually. The default `warning` failure threshold does not fail CI on this informational diagnostic.\n\n"""
if marker not in rules:
    raise SystemExit("TG016 rules marker missing")
rules = rules.replace(marker, addition + marker, 1)
write("docs/RULES.md", rules)

# Design/analysis docs: remove obsolete v0.1 limitations and describe bounded data flow.
design = read("docs/DESIGN.md")
design = design.replace(
    "The first release intentionally stops short of a full PHP call-graph engine. Trait-based and array-form Laravel 13 queue routes, `Queue::forward()`, queue attributes/enums, arbitrary observer/listener indirection, and runtime configuration mutation remain documented limitations rather than guessed behavior. Critical cross-system atomic delivery remains an application architecture concern; use a transactional outbox/idempotency where required.",
    "The analyzer intentionally stops short of a full PHP call-graph engine. It supports bounded local inference for closure variables, payload variables and common Laravel facade-derived handles, plus statically resolvable Laravel 13 queue routing/forwarding/attributes. Arbitrary observer/listener indirection, container bindings and runtime configuration mutation remain documented limitations rather than guessed behavior. Critical cross-system atomic delivery remains an application architecture concern; use a transactional outbox/idempotency where required.",
    1,
)
write("docs/DESIGN.md", design)

analysis = read("docs/ANALYSIS.md")
analysis += """\n\n## v0.2 bounded local data flow\n\nThe analyzer resolves simple local closure variables passed to `DB::transaction()`, simple local job/event/notification/broadcast payload assignments, and locally assigned Laravel HTTP/filesystem/cache/Redis/process/database handles. Any later unknown reassignment invalidates the inference. This deliberately increases signal without turning Transaction Guard into a general PHP call-graph engine.\n\nStatically known Eloquent model connections, including Laravel 13 `#[Connection]`, participate in `TG021` cross-connection analysis. `TG012` also uses the configured database driver: MySQL/MariaDB implicit-commit hazards remain critical, while drivers with broadly transactional DDL remain visible as warnings rather than being mislabeled as identical MySQL semantics.\n"""
write("docs/ANALYSIS.md", analysis)

scenario_docs = read("docs/SCENARIO-MATRIX.md")
scenario_docs = scenario_docs.replace(
    "- arrow functions;\n",
    "- arrow functions;\n- simple local closure variables passed to `DB::transaction()` plus unresolved-callback diagnostics;\n",
    1,
)
scenario_docs = scenario_docs.replace(
    "- Laravel concurrency/deferred execution;\n",
    "- Laravel concurrency/deferred execution;\n- locally assigned HTTP/filesystem/cache/Redis/process/database handles;\n- Eloquent cross-connection writes with statically known model connections;\n",
    1,
)
write("docs/SCENARIO-MATRIX.md", scenario_docs)

# Composer script metadata.
composer = read("composer.json")
composer = composer.replace(
    '"test:smoke": "Run the dependency-free transaction-safety regression matrix."',
    '"test:smoke": "Run the dependency-free transaction-safety regression matrix.",\n            "benchmark": "Profile representative transaction-free, safe and side-effect-heavy analyzer workloads."',
    1,
)
write("composer.json", composer)

# ---------------------------------------------------------------------------
# Remove temporary audit machinery after using the one-shot owner workflow.
# ---------------------------------------------------------------------------
for path in [
    ".audit/point1",
    ".github/audit_writer.py",
    ".github/workflows/audit-maintenance.yml",
    ".github/workflows/audit-point1-target.yml",
    ".github/workflows/audit-point1.yml",
    ".github/workflows/temporary-maintenance.yml",
]:
    remove(path)

try:
    Path('.audit').rmdir()
except OSError:
    pass

print('v0.2.0 hardening patch applied')
