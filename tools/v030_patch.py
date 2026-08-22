from __future__ import annotations

from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[1]


def read(path: str) -> str:
    return (ROOT / path).read_text()


def write(path: str, text: str) -> None:
    target = ROOT / path
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_text(text)


def replace(path: str, old: str, new: str, count: int = 1) -> None:
    text = read(path)
    if old not in text:
        raise RuntimeError(f"missing replacement anchor in {path}: {old[:100]!r}")
    write(path, text.replace(old, new, count))


def regex(path: str, pattern: str, repl: str, count: int = 1, flags: int = 0) -> None:
    text = read(path)
    updated, n = re.subn(pattern, repl, text, count=count, flags=flags)
    if n != count:
        raise RuntimeError(f"regex replacement count {n} != {count} in {path}: {pattern}")
    write(path, updated)


# ---------------------------------------------------------------------------
# Central rule catalog: one source for config validation, SARIF and docs checks.
# ---------------------------------------------------------------------------
write('src/Analysis/RuleCatalog.php', r'''<?php

declare(strict_types=1);

namespace Codegenie\TransactionGuard\Analysis;

final class RuleCatalog
{
    /** @var array<string, array{title:string,description:string}> */
    private const RULES = [
        'TG001' => ['title' => 'Queued work before commit', 'description' => 'Queued work may escape the surrounding database transaction before commit.'],
        'TG002' => ['title' => 'Event before commit', 'description' => 'An event may execute listeners before the surrounding transaction commits.'],
        'TG003' => ['title' => 'Mail before commit', 'description' => 'Mail may be sent or queued before the surrounding transaction commits.'],
        'TG004' => ['title' => 'Notification before commit', 'description' => 'A notification may be delivered before the surrounding transaction commits.'],
        'TG005' => ['title' => 'Broadcast before commit', 'description' => 'A broadcast may run before the surrounding transaction commits.'],
        'TG006' => ['title' => 'Outbound HTTP inside transaction', 'description' => 'External HTTP I/O is executed while a database transaction is open.'],
        'TG007' => ['title' => 'Filesystem mutation inside transaction', 'description' => 'Filesystem state is mutated while a database transaction is open.'],
        'TG008' => ['title' => 'Cache mutation inside transaction', 'description' => 'Cache state is mutated while a database transaction is open.'],
        'TG009' => ['title' => 'External process inside transaction', 'description' => 'An external process is started while a database transaction is open.'],
        'TG010' => ['title' => 'Explicit beforeCommit override', 'description' => 'beforeCommit() explicitly opts out of commit-aware dispatch.'],
        'TG011' => ['title' => 'Retry duplicate risk', 'description' => 'A non-transactional effect may run more than once when a transaction retries.'],
        'TG012' => ['title' => 'Implicit commit / DDL risk', 'description' => 'DDL or driver-specific statements may break transaction semantics.'],
        'TG013' => ['title' => 'Unclosed manual transaction', 'description' => 'A manually started transaction is not closed on every statically visible path.'],
        'TG014' => ['title' => 'Unresolved transaction callback', 'description' => 'The transaction callback could not be resolved statically.'],
        'TG016' => ['title' => 'Synchronous job dispatch', 'description' => 'A job executes synchronously while the transaction is still open.'],
        'TG017' => ['title' => 'After-response is not after-commit', 'description' => 'Response deferral is not proof of a successful database commit.'],
        'TG018' => ['title' => 'Concurrency/deferred work', 'description' => 'Concurrent or deferred work is outside the current transaction boundary.'],
        'TG020' => ['title' => 'Redis mutation inside transaction', 'description' => 'Redis state is mutated while the SQL database transaction is open.'],
        'TG021' => ['title' => 'Cross-connection database write', 'description' => 'A database write uses a different connection from the active transaction.'],
        'TG100' => ['title' => 'Configured custom side effect', 'description' => 'A configured project-specific side effect runs inside a transaction.'],
        'TG900' => ['title' => 'Unreadable source file', 'description' => 'The analyzer could not read a requested PHP source file.'],
        'TG901' => ['title' => 'PHP parse failure', 'description' => 'The analyzer could not parse a requested PHP source file.'],
        'TG902' => ['title' => 'Analyzer regular-expression failure', 'description' => 'A scanner regular expression failed at analysis time and results may be incomplete.'],
    ];

    /** @return list<string> */
    public static function ids(): array
    {
        return array_keys(self::RULES);
    }

    public static function exists(string $rule): bool
    {
        return isset(self::RULES[strtoupper($rule)]);
    }

    public static function isDiagnostic(string $rule): bool
    {
        return in_array(strtoupper($rule), ['TG900', 'TG901', 'TG902'], true);
    }

    /** @return array{title:string,description:string} */
    public static function definition(string $rule): array
    {
        $rule = strtoupper($rule);
        if (! isset(self::RULES[$rule])) {
            throw new \InvalidArgumentException("Unknown Transaction Guard rule [{$rule}].");
        }

        return self::RULES[$rule];
    }

    public static function helpUri(string $rule): string
    {
        return 'https://github.com/Codegenie-BE/laravel-transaction-guard/blob/main/docs/RULES.md#'.strtolower($rule);
    }
}
''')

# ---------------------------------------------------------------------------
# Configuration: validate rule IDs, compile regexes once, project root + empty scan.
# ---------------------------------------------------------------------------
replace('src/Analysis/AnalysisConfig.php',
"""    /** @var array<string, true> */
    private array $disabledRuleLookup;
""",
"""    /** @var array<string, true> */
    private array $disabledRuleLookup;

    /** @var list<string> */
    private array $compiledCustomSideEffectPatterns = [];
""")
replace('src/Analysis/AnalysisConfig.php',
"""        public string $defaultDatabaseConnection = '@default',
        public array $databaseDriverByConnection = [],
    ) {
        $this->disabledRuleLookup = array_fill_keys($this->disabledRules, true);

        foreach ($this->customSideEffectPatterns as $pattern) {
            $regex = str_starts_with($pattern, '/') ? $pattern : '/'.str_replace('/', '\\\\/', $pattern).'/';
            set_error_handler(static fn (): bool => true);
            try {
                $valid = preg_match($regex, '') !== false;
            } finally {
                restore_error_handler();
            }

            if (! $valid) {
                throw new \\InvalidArgumentException(\"Invalid custom side-effect regular expression [{$pattern}].\");
            }
        }
    }
""",
"""        public string $defaultDatabaseConnection = '@default',
        public array $databaseDriverByConnection = [],
        public bool $allowEmptyScan = false,
        public string $projectRoot = '',
    ) {
        $normalizedDisabled = [];
        foreach ($this->disabledRules as $rule) {
            $rule = strtoupper(trim($rule));
            if (! RuleCatalog::exists($rule)) {
                throw new \\InvalidArgumentException(\"Unknown Transaction Guard rule [{$rule}] in disabled_rules.\");
            }
            if (RuleCatalog::isDiagnostic($rule)) {
                throw new \\InvalidArgumentException(\"Analyzer diagnostic [{$rule}] cannot be disabled.\");
            }
            $normalizedDisabled[$rule] = true;
        }
        $this->disabledRuleLookup = $normalizedDisabled;

        foreach ($this->customSideEffectPatterns as $pattern) {
            $regex = str_starts_with($pattern, '/') ? $pattern : '/'.str_replace('/', '\\\\/', $pattern).'/';
            set_error_handler(static fn (): bool => true);
            try {
                $valid = preg_match($regex, '') !== false;
            } finally {
                restore_error_handler();
            }

            if (! $valid) {
                throw new \\InvalidArgumentException(\"Invalid custom side-effect regular expression [{$pattern}].\");
            }
            $this->compiledCustomSideEffectPatterns[] = $regex;
        }
    }

    /** @return list<string> */
    public function customRegexes(): array
    {
        return $this->compiledCustomSideEffectPatterns;
    }
""")
replace('src/Analysis/AnalysisConfig.php',
"""    public function ruleEnabled(string $rule): bool
    {
        return ! isset($this->disabledRuleLookup[$rule]);
    }
""",
"""    public function ruleEnabled(string $rule): bool
    {
        return RuleCatalog::isDiagnostic($rule) || ! isset($this->disabledRuleLookup[strtoupper($rule)]);
    }
""")

# ---------------------------------------------------------------------------
# Finding locations + deterministic fingerprints relative to configured root.
# ---------------------------------------------------------------------------
replace('src/Analysis/Finding.php',
"""        public string $confidence = 'high',
        public array $context = [],
    ) {}
""",
"""        public string $confidence = 'high',
        public array $context = [],
        public ?int $column = null,
        public ?int $endColumn = null,
        public string $projectRoot = '',
    ) {}
""")
regex('src/Analysis/Finding.php', r"\n        \$cwd = getcwd\(\);.*?\n        }\n", r'''
        $root = $this->projectRoot;
        if ($root !== '') {
            $root = rtrim(str_replace('\\', '/', realpath($root) ?: $root), '/').'/';
            if (str_starts_with($file, $root)) {
                $file = substr($file, strlen($root));
            }
        }
''', flags=re.S)
replace('src/Analysis/Finding.php',
"""            'line' => $this->line,
            'snippet' => $this->snippet,
""",
"""            'line' => $this->line,
            'column' => $this->column,
            'end_column' => $this->endColumn,
            'snippet' => $this->snippet,
""")

# ---------------------------------------------------------------------------
# SourceIndex: inline HTML is non-code and columns are first-class.
# ---------------------------------------------------------------------------
replace('src/Analysis/SourceIndex.php',
"$ignored = [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE];",
"$ignored = [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML];")
replace('src/Analysis/SourceIndex.php',
"""    public function line(int $number): string
    {
        return $this->lines[$number - 1] ?? '';
    }
""",
"""    public function line(int $number): string
    {
        return $this->lines[$number - 1] ?? '';
    }

    public function columnAt(int $offset): int
    {
        $line = $this->lineAt($offset);
        $start = $this->lineStarts[$line - 1] ?? 0;

        return max(1, $offset - $start + 1);
    }
""")

# ---------------------------------------------------------------------------
# AnalysisResult + TransactionGuard: diagnostics cannot be baselined or hidden.
# ---------------------------------------------------------------------------
write('src/Analysis/AnalysisResult.php', r'''<?php

declare(strict_types=1);

namespace Codegenie\TransactionGuard\Analysis;

final readonly class AnalysisResult
{
    /**
     * @param list<Finding> $findings
     * @param list<Finding> $diagnostics
     */
    public function __construct(
        public array $findings,
        public int $filesAnalyzed,
        public array $diagnostics = [],
    ) {}

    /** @return list<Finding> */
    public function all(): array
    {
        return array_merge($this->diagnostics, $this->findings);
    }

    public function hasDiagnostics(): bool
    {
        return $this->diagnostics !== [];
    }

    public function highestSeverity(): ?Severity
    {
        $highest = null;
        foreach ($this->all() as $finding) {
            if ($highest === null || $finding->severity->value > $highest->value) {
                $highest = $finding->severity;
            }
        }

        return $highest;
    }
}
''')
replace('src/TransactionGuard.php',
"""        $files = $this->discoverPhpFiles($paths, $excludePatterns);
        $index = ClassMetadataIndex::fromFiles($files);
""",
"""        $files = $this->discoverPhpFiles($paths, $excludePatterns);
        if ($files === [] && ! $this->config->allowEmptyScan) {
            throw new \\InvalidArgumentException('Transaction Guard did not discover any PHP files to analyze.');
        }
        $index = ClassMetadataIndex::fromFiles($files);
""")
replace('src/TransactionGuard.php',
"""        $findings = [];
        $baselineOccurrences = [];

        foreach ($files as $file) {
            foreach ($scanner->scan($file) as $finding) {
                if ($baseline !== null) {
""",
"""        $findings = [];
        $diagnostics = [];
        $baselineOccurrences = [];

        foreach ($files as $file) {
            foreach ($scanner->scan($file) as $finding) {
                if (RuleCatalog::isDiagnostic($finding->rule)) {
                    $diagnostics[] = $finding;
                    continue;
                }
                if ($baseline !== null) {
""")
replace('src/TransactionGuard.php',
"""        return new AnalysisResult($findings, count($files));
""",
"""        usort($diagnostics, static fn (Finding $a, Finding $b): int => [str_replace('\\\\', '/', $a->file), $a->line, $a->rule] <=> [str_replace('\\\\', '/', $b->file), $b->line, $b->rule]);

        return new AnalysisResult($findings, count($files), $diagnostics);
""")
replace('src/TransactionGuard.php',
"""        foreach ($paths as $path) {
            if (is_file($path) && str_ends_with(strtolower($path), '.php')) {
""",
"""        foreach ($paths as $path) {
            if (! file_exists($path) && ! $this->excluded($path, $excludePatterns)) {
                throw new \\InvalidArgumentException(\"Scan path does not exist [{$path}].\");
            }
            if (is_file($path) && str_ends_with(strtolower($path), '.php')) {
""")

# ---------------------------------------------------------------------------
# Baseline: diagnostics are excluded and output is deterministic.
# ---------------------------------------------------------------------------
replace('src/Analysis/Baseline.php',
"""        foreach ($findings as $finding) {
            $fingerprint = $finding->fingerprint();
""",
"""        foreach ($findings as $finding) {
            if (RuleCatalog::isDiagnostic($finding->rule)) {
                continue;
            }
            $fingerprint = $finding->fingerprint();
""")
replace('src/Analysis/Baseline.php',
"""        $payload = [
            'version' => 2,
            'generated_at' => gmdate(DATE_ATOM),
            'fingerprints' => $fingerprints,
        ];
""",
"""        $payload = [
            'version' => 2,
            'fingerprints' => $fingerprints,
        ];
""")

# ---------------------------------------------------------------------------
# SourceScanner reliability: diagnostics, global-call checks, local ambiguity,
# top-level facade-chain matching, early exits and broader Eloquent mutations.
# ---------------------------------------------------------------------------
replace('src/Analysis/SourceScanner.php',
"""    /** @var list<Finding> */
    private array $preScanFindings = [];
""",
"""    /** @var list<Finding> */
    private array $preScanFindings = [];

    /** @var array<string, true> */
    private array $regexErrors = [];
""")
replace('src/Analysis/SourceScanner.php',
"""                confidence: 'high',
            )];
""",
"""                confidence: 'high',
                projectRoot: $this->config->projectRoot,
            )];
""", count=1)
replace('src/Analysis/SourceScanner.php',
"""                confidence: 'high',
            )];
        }

        $this->sourceIndex = new SourceIndex($source, $this->tokens);
""",
"""                confidence: 'high',
                projectRoot: $this->config->projectRoot,
            )];
        }

        $this->sourceIndex = new SourceIndex($source, $this->tokens);
""")
replace('src/Analysis/SourceScanner.php',
"""        $this->preScanFindings = [];

        $this->callables = $this->findCallableRegions();
""",
"""        $this->preScanFindings = [];
        $this->regexErrors = [];

        $this->callables = $this->findCallableRegions();
""")
# Native/global calls must not match object/static methods.
replace('src/Analysis/SourceScanner.php',
"""        foreach ($this->matches('/\\b(?P<fn>file_put_contents|unlink|rename|mkdir|rmdir|copy|touch|chmod|symlink|link)\\s*\\(/i') as $match) {
            $offset = $match['offset'];
""",
"""        foreach ($this->matches('/\\b(?P<fn>file_put_contents|unlink|rename|mkdir|rmdir|copy|touch|chmod|symlink|link)\\s*\\(/i') as $match) {
            $offset = $match['offset'];
            if (! $this->isGlobalFunctionCall($offset)) {
                continue;
            }
""")
replace('src/Analysis/SourceScanner.php',
"""        foreach ($this->matches('/\\b(?P<fn>exec|shell_exec|system|passthru|proc_open)\\s*\\(/i') as $match) {
            $offset = $match['offset'];
""",
"""        foreach ($this->matches('/\\b(?P<fn>exec|shell_exec|system|passthru|proc_open)\\s*\\(/i') as $match) {
            $offset = $match['offset'];
            if (! $this->isGlobalFunctionCall($offset)) {
                continue;
            }
""")
replace('src/Analysis/SourceScanner.php',
"""        foreach ($this->matches('/(?<![A-Za-z0-9_])defer\\s*\\(/i') as $match) {
            $offset = $match['offset'];
""",
"""        foreach ($this->matches('/(?<![A-Za-z0-9_])defer\\s*\\(/i') as $match) {
            $offset = $match['offset'];
            if (! $this->isGlobalFunctionCall($offset)) {
                continue;
            }
""")
# Broader Eloquent writes.
replace('src/Analysis/SourceScanner.php',
"$methods = 'create|forceCreate|updateOrCreate|firstOrCreate|upsert|insert|insertOrIgnore|update|delete|destroy|truncate|increment|decrement';",
"$methods = 'create|forceCreate|updateOrCreate|firstOrCreate|upsert|insert|insertOrIgnore|update|delete|destroy|truncate|increment|decrement|forceDelete|restore';")
replace('src/Analysis/SourceScanner.php',
"""        foreach ($this->matches('/(?P<var>\\$[A-Za-z_][A-Za-z0-9_]*)\\s*->\\s*(?P<method>save|delete|increment|decrement)\\s*\\(/i') as $match) {
""",
"""        foreach ($this->matches('/(?P<var>\\$[A-Za-z_][A-Za-z0-9_]*)\\s*->\\s*(?P<method>save|saveQuietly|update|updateQuietly|delete|deleteQuietly|forceDelete|forceDeleteQuietly|restore|restoreQuietly|touch|touchQuietly|push|pushQuietly|increment|decrement)\\s*\\(/i') as $match) {
""")
# Compiled custom regexes.
replace('src/Analysis/SourceScanner.php',
"""        foreach ($this->config->customSideEffectPatterns as $pattern) {
            $regex = str_starts_with($pattern, '/') ? $pattern : '/'.str_replace('/', '\\\\/', $pattern).'/';
            foreach ($this->matches($regex) as $match) {
""",
"""        foreach ($this->config->customRegexes() as $regex) {
            foreach ($this->matches($regex) as $match) {
""")
# Conservative local value inference: more than one assignment is ambiguous.
replace('src/Analysis/SourceScanner.php',
"""        $resolved = null;
        $count = count($this->tokens);
        for ($i = 0; $i < $count; $i++) {
""",
"""        $resolved = null;
        $assignments = 0;
        $count = count($this->tokens);
        for ($i = 0; $i < $count; $i++) {
""", count=1)
replace('src/Analysis/SourceScanner.php',
"""            $assign = $this->nextSignificantToken($i + 1);
            if ($assign === null || $this->tokens[$assign]['text'] !== '=') {
                continue;
            }

            $value = $this->nextSignificantToken($assign + 1);
""",
"""            $assign = $this->nextSignificantToken($i + 1);
            if ($assign === null || $this->tokens[$assign]['text'] !== '=') {
                continue;
            }
            $assignments++;
            if ($assignments > 1 || $this->conditionalControlScopeAt($token['offset']) !== null) {
                return null;
            }

            $value = $this->nextSignificantToken($assign + 1);
""", count=1)
# localNewClassForVariable section (second assignment loop)
anchor = """        $resolved = null;
        $count = count($this->tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $this->tokens[$i];
            if ($token['offset'] < $scopeStart || $token['offset'] >= $offset || $token['id'] !== T_VARIABLE || $token['text'] !== $variable) {
"""
replace('src/Analysis/SourceScanner.php', anchor, anchor.replace("$resolved = null;\n        $count", "$resolved = null;\n        $assignments = 0;\n        $count"))
replace('src/Analysis/SourceScanner.php',
"""            $assign = $this->nextSignificantToken($i + 1);
            if ($assign === null || $this->tokens[$assign]['text'] !== '=') {
                continue;
            }

            $value = $this->nextSignificantToken($assign + 1);
            if ($value === null || $this->tokens[$value]['id'] !== T_NEW) {
""",
"""            $assign = $this->nextSignificantToken($i + 1);
            if ($assign === null || $this->tokens[$assign]['text'] !== '=') {
                continue;
            }
            $assignments++;
            if ($assignments > 1 || $this->conditionalControlScopeAt($token['offset']) !== null) {
                return null;
            }

            $value = $this->nextSignificantToken($assign + 1);
            if ($value === null || $this->tokens[$value]['id'] !== T_NEW) {
""", count=1)
# localFacadeHandleForVariable section.
replace('src/Analysis/SourceScanner.php',
"""        $resolved = null;
        $count = count($this->tokens);
        $facades = [
""",
"""        $resolved = null;
        $assignments = 0;
        $count = count($this->tokens);
        $facades = [
""")
replace('src/Analysis/SourceScanner.php',
"""            $assign = $this->nextSignificantToken($i + 1);
            if ($assign === null || $this->tokens[$assign]['text'] !== '=') {
                continue;
            }

            $raw = $this->statementAt($token['offset']);
""",
"""            $assign = $this->nextSignificantToken($i + 1);
            if ($assign === null || $this->tokens[$assign]['text'] !== '=') {
                continue;
            }
            $assignments++;
            if ($assignments > 1 || $this->conditionalControlScopeAt($token['offset']) !== null) {
                return null;
            }

            $raw = $this->statementAt($token['offset']);
""")
# Manual transaction early exits keep the begin on the balance stack.
replace('src/Analysis/SourceScanner.php',
"""            if (! $this->manualTerminalCloses($start, $call)) {
                continue;
            }

            array_pop($stacks[$key]);
""",
"""            if (! $this->manualTerminalCloses($start, $call) || $this->manualRegionHasEarlyExit($start, $call)) {
                continue;
            }

            array_pop($stacks[$key]);
""")
# Regex execution diagnostics + top-level chain check.
replace('src/Analysis/SourceScanner.php',
"""        $ok = @preg_match_all($pattern, $this->source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        if ($ok === false || $ok === 0) {
            return [];
        }

        foreach ($matches as $match) {
""",
"""        $ok = @preg_match_all($pattern, $this->source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        if ($ok === false) {
            $key = hash('sha256', $pattern.'|'.preg_last_error_msg());
            if (! isset($this->regexErrors[$key])) {
                $this->regexErrors[$key] = true;
                $this->preScanFindings[] = new Finding(
                    rule: 'TG902',
                    severity: Severity::Error,
                    message: 'Analyzer regular expression failed: '.preg_last_error_msg(),
                    file: $this->file,
                    line: 1,
                    snippet: '',
                    remediation: 'Report this analyzer failure; analysis results for this file may be incomplete.',
                    confidence: 'high',
                    projectRoot: $this->config->projectRoot,
                );
            }
            return [];
        }
        if ($ok === 0) {
            return [];
        }

        foreach ($matches as $match) {
""")
replace('src/Analysis/SourceScanner.php',
"""            if ($this->offsetIsNonCode($offset) || $this->semanticCaptureIsNonCode($match, $allowNonCodeCaptures)) {
                continue;
            }
            $result[] = ['offset' => $offset, 'matches' => $match];
""",
"""            if ($this->offsetIsNonCode($offset) || $this->semanticCaptureIsNonCode($match, $allowNonCodeCaptures)) {
                continue;
            }
            if (! $this->capturedMethodIsTopLevel($match)) {
                continue;
            }
            $result[] = ['offset' => $offset, 'matches' => $match];
""")
# Finding location details.
replace('src/Analysis/SourceScanner.php',
"""            confidence: $confidence,
            context: $context,
        );
""",
"""            confidence: $confidence,
            context: $context,
            column: $this->sourceIndex->columnAt($offset),
            endColumn: $this->sourceIndex->columnAt($offset) + max(0, strlen($this->capturedTokenTextAt($offset)) - 1),
            projectRoot: $this->config->projectRoot,
        );
""")
# Helper block before statementAt().
replace('src/Analysis/SourceScanner.php',
"""    private function statementAt(int $offset): string
    {
""",
r'''    private function isGlobalFunctionCall(int $offset): bool
    {
        $index = $this->tokenIndexContainingOrAfterOffset($offset);
        if ($index === null) {
            return false;
        }
        $previous = $this->previousSignificantToken($index - 1);
        if ($previous === null) {
            return true;
        }

        return ! in_array($this->tokens[$previous]['id'], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true);
    }

    /** @param array<int|string, mixed> $match */
    private function capturedMethodIsTopLevel(array $match): bool
    {
        $capture = $match['method'] ?? null;
        if (! is_array($capture) || ! isset($capture[1]) || ! is_int($capture[1]) || $capture[1] < 0) {
            return true;
        }
        $full = $match[0] ?? null;
        if (! is_array($full) || ! isset($full[0], $full[1]) || ! is_string($full[0]) || ! is_int($full[1])) {
            return true;
        }
        $prefixLength = $capture[1] - $full[1];
        if ($prefixLength <= 0) {
            return true;
        }
        $prefix = substr($full[0], 0, $prefixLength);
        if (! str_contains($prefix, '::')) {
            return true;
        }

        $paren = $bracket = $brace = 0;
        foreach (token_get_all('<?php '.$prefix) as $token) {
            if (is_array($token)) {
                continue;
            }
            if ($token === '(') $paren++;
            elseif ($token === ')') $paren--;
            elseif ($token === '[') $bracket++;
            elseif ($token === ']') $bracket--;
            elseif ($token === '{') $brace++;
            elseif ($token === '}') $brace--;
        }

        return $paren === 0 && $bracket === 0 && $brace === 0;
    }

    /**
     * @param DatabaseControlCall $start
     * @param DatabaseControlCall $terminal
     */
    private function manualRegionHasEarlyExit(array $start, array $terminal): bool
    {
        $hasCatchOrFinally = false;
        foreach ($this->tokens as $token) {
            if ($token['offset'] <= $start['end'] || $token['offset'] >= $terminal['offset']) {
                continue;
            }
            if (in_array($token['id'], [T_CATCH, T_FINALLY], true)) {
                $hasCatchOrFinally = true;
            }
            if (in_array($token['id'], [T_RETURN, T_EXIT], true)) {
                return true;
            }
            if ($token['id'] === T_THROW && ! $hasCatchOrFinally) {
                return true;
            }
        }

        return false;
    }

    private function capturedTokenTextAt(int $offset): string
    {
        $index = $this->tokenIndexContainingOrAfterOffset($offset);

        return $index === null ? '' : $this->tokens[$index]['text'];
    }

    private function statementAt(int $offset): string
    {
''')

# ---------------------------------------------------------------------------
# Class metadata: lazy Composer file resolution, enum values, dynamic notification
# connection state and inline HTML masking.
# ---------------------------------------------------------------------------
replace('src/Analysis/ClassMetadataIndex.php',
"""    /** @var array<string, list<string>> */
    private array $interfaceParents = [];
""",
"""    /** @var array<string, list<string>> */
    private array $interfaceParents = [];

    /** @var array<string, true> */
    private array $indexedFiles = [];

    /** @var array<string, true> */
    private array $indexingClasses = [];

    /** @var array<string, string> */
    private array $enumCaseValues = [];
""")
replace('src/Analysis/ClassMetadataIndex.php',
"""    public function metadata(string $class): ?ClassMetadata
    {
        return $this->classes[strtolower(ltrim($class, '\\\\'))] ?? null;
    }
""",
"""    public function metadata(string $class): ?ClassMetadata
    {
        $key = strtolower(ltrim($class, '\\\\'));
        if (! isset($this->classes[$key])) {
            $this->ensureClassIndexed($class);
        }

        return $this->classes[$key] ?? null;
    }
""")
replace('src/Analysis/ClassMetadataIndex.php',
"""    public function indexFile(string $file): void
    {
        $source = @file_get_contents($file);
""",
"""    public function indexFile(string $file): void
    {
        $real = realpath($file) ?: $file;
        if (isset($this->indexedFiles[$real])) {
            return;
        }
        $this->indexedFiles[$real] = true;

        $source = @file_get_contents($file);
""")
replace('src/Analysis/ClassMetadataIndex.php',
"""        $this->indexInterfaceDeclarations($tokens, $context);
        $this->indexQueueRoutes($source, $tokens, $context);
""",
"""        $this->indexInterfaceDeclarations($tokens, $context);
        $this->indexEnumDeclarations($tokens, $context);
        $this->indexQueueRoutes($source, $tokens, $context);
""")
# Dynamic viaConnections sentinel.
replace('src/Analysis/ClassMetadataIndex.php', "return [];\n            }\n            $close = $this->matchingBrace($tokens, $open, $end);", "return ['@dynamic' => '@dynamic'];\n            }\n            $close = $this->matchingBrace($tokens, $open, $end);", count=1)
replace('src/Analysis/ClassMetadataIndex.php', "return [];\n            }\n\n            $bodyStart = $tokens[$open]['offset'] + 1;", "return ['@dynamic' => '@dynamic'];\n            }\n\n            $bodyStart = $tokens[$open]['offset'] + 1;", count=1)
replace('src/Analysis/ClassMetadataIndex.php',
"""            if (preg_match('/\\breturn\\s*\\[(?<items>.*?)\\]\\s*;/s', $body, $match) !== 1) {
                return [];
            }
""",
"""            if (preg_match('/\\breturn\\s*\\[(?<items>.*?)\\]\\s*;/s', $body, $match) !== 1) {
                return ['@dynamic' => '@dynamic'];
            }
""")
# T_INLINE_HTML in metadata scanner.
replace('src/Analysis/ClassMetadataIndex.php',
"[T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE]",
"[T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML]")
# isEloquentModel resolves unknown parent files lazily.
replace('src/Analysis/ClassMetadataIndex.php',
"""            $current = $this->metadata($parent);
        }
""",
"""            $current = $this->metadata($parent);
        }
""", count=1)
# literal enum support in route/forward and model connection expressions.
replace('src/Analysis/ClassMetadataIndex.php',
"$this->literalString($connectionExpression) ?? '@dynamic';",
"$this->literalStringOrEnum($connectionExpression, $context) ?? '@dynamic';")
replace('src/Analysis/ClassMetadataIndex.php',
"$connection = $this->literalString($value) ?? '@dynamic';",
"$connection = $this->literalStringOrEnum($value, $context) ?? '@dynamic';", count=2)
# parseQueueForwardArguments needs context.
replace('src/Analysis/ClassMetadataIndex.php', "$this->parseQueueForwardArguments($arguments);", "$this->parseQueueForwardArguments($arguments, $context);")
replace('src/Analysis/ClassMetadataIndex.php', "private function parseQueueForwardArguments(string $arguments): void", "private function parseQueueForwardArguments(string $arguments, FileContext $context): void")
# Remaining forward literal occurrences after signature change.
replace('src/Analysis/ClassMetadataIndex.php', "$connection = $this->literalString($value) ?? '@dynamic';", "$connection = $this->literalStringOrEnum($value, $context) ?? '@dynamic';", count=2)
# model property literal enum.
replace('src/Analysis/ClassMetadataIndex.php', "return $this->literalString($expression) ?? '@dynamic';", "return $this->literalStringOrEnum($expression, $context) ?? '@dynamic';", count=1)
# Attribute parsing: recognize enum case argument before declaring dynamic.
replace('src/Analysis/ClassMetadataIndex.php',
"""                if (preg_match('/(?:^|,)\\s*'.$name.'\\s*\\(/s', $block['attributes']) === 1) {
                    return '@dynamic';
                }
""",
"""                if (preg_match('/(?:^|,)\\s*'.$name.'\\s*\\(\\s*(?:'.preg_quote($argumentName, '/').'\\s*:\\s*)?(?<value>[A-Za-z_\\\\][A-Za-z0-9_\\\\]*::[A-Za-z_][A-Za-z0-9_]*)\\s*\\)/s', $block['attributes'], $enum) === 1) {
                    return $this->literalStringOrEnum($enum['value'], $context) ?? '@dynamic';
                }
                if (preg_match('/(?:^|,)\\s*'.$name.'\\s*\\(/s', $block['attributes']) === 1) {
                    return '@dynamic';
                }
""")
# Helpers before literalString().
replace('src/Analysis/ClassMetadataIndex.php',
"""    private function literalString(string $expression): ?string
    {
""",
r'''    private function ensureClassIndexed(string $class): void
    {
        $class = ltrim($class, '\\');
        $key = strtolower($class);
        if (isset($this->classes[$key], $this->indexingClasses[$key])) {
            return;
        }
        $this->indexingClasses[$key] = true;

        try {
            foreach (spl_autoload_functions() ?: [] as $autoload) {
                $loader = is_array($autoload) ? ($autoload[0] ?? null) : null;
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
    private function indexEnumDeclarations(array $tokens, FileContext $context): void
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
''')

# ---------------------------------------------------------------------------
# Command: explain rules, diagnostics always fail, project root, columns/SARIF.
# ---------------------------------------------------------------------------
replace('src/Console/TransactionGuardCommand.php',
"""        {--generate-baseline : Write all current findings to the baseline and exit successfully}';
""",
"""        {--generate-baseline : Write all current findings to the baseline and exit successfully}
        {--explain= : Explain one rule ID and exit}';
""")
replace('src/Console/TransactionGuardCommand.php',
"""use Codegenie\\TransactionGuard\\Analysis\\Finding;
use Codegenie\\TransactionGuard\\Analysis\\Severity;
""",
"""use Codegenie\\TransactionGuard\\Analysis\\Finding;
use Codegenie\\TransactionGuard\\Analysis\\RuleCatalog;
use Codegenie\\TransactionGuard\\Analysis\\Severity;
""")
replace('src/Console/TransactionGuardCommand.php',
"""    public function handle(): int
    {
        $formatOption = $this->option('format');
""",
"""    public function handle(): int
    {
        $explain = $this->option('explain');
        if (is_string($explain) && $explain !== '') {
            $rule = strtoupper($explain);
            if (! RuleCatalog::exists($rule)) {
                $this->error(\"Unknown Transaction Guard rule [{$rule}].\");
                return self::INVALID;
            }
            $definition = RuleCatalog::definition($rule);
            $this->line($rule.' — '.$definition['title']);
            $this->line($definition['description']);
            $this->line(RuleCatalog::helpUri($rule));
            return self::SUCCESS;
        }

        $formatOption = $this->option('format');
""")
replace('src/Console/TransactionGuardCommand.php',
"""                databaseDriverByConnection: $this->databaseDriverMap(),
            );
""",
"""                databaseDriverByConnection: $this->databaseDriverMap(),
                allowEmptyScan: (bool) config('transaction-guard.allow_empty_scan', false),
                projectRoot: base_path(),
            );
""")
replace('src/Console/TransactionGuardCommand.php',
"""        if ((bool) $this->option('generate-baseline')) {
            try {
                Baseline::write($baselinePath, $result->findings);
""",
"""        if ((bool) $this->option('generate-baseline')) {
            if ($result->hasDiagnostics()) {
                $this->render($format, $result->all(), $result->filesAnalyzed);
                $this->error('Baseline was not generated because analyzer diagnostics must be fixed first.');
                return self::FAILURE;
            }
            try {
                Baseline::write($baselinePath, $result->findings);
""")
replace('src/Console/TransactionGuardCommand.php', "$this->render($format, $result->findings, $result->filesAnalyzed);", "$this->render($format, $result->all(), $result->filesAnalyzed);")
replace('src/Console/TransactionGuardCommand.php',
"""        if ($failOn === 'never') {
            return self::SUCCESS;
        }
""",
"""        if ($result->hasDiagnostics()) {
            return self::FAILURE;
        }
        if ($failOn === 'never') {
            return self::SUCCESS;
        }
""")
replace('src/Console/TransactionGuardCommand.php',
"""                $this->line(sprintf('::%s file=%s,line=%d::%s', $level, $file, $finding->line, $message));
""",
"""                $column = $finding->column !== null ? ',col='.$finding->column : '';
                $this->line(sprintf('::%s file=%s,line=%d%s::%s', $level, $file, $finding->line, $column, $message));
""")
replace('src/Console/TransactionGuardCommand.php',
"""            $rules[$finding->rule] ??= [
                'id' => $finding->rule,
                'name' => $finding->rule,
                'shortDescription' => ['text' => $finding->message],
                'help' => ['text' => $finding->remediation],
            ];
""",
"""            $definition = RuleCatalog::definition($finding->rule);
            $rules[$finding->rule] ??= [
                'id' => $finding->rule,
                'name' => $definition['title'],
                'shortDescription' => ['text' => $definition['description']],
                'helpUri' => RuleCatalog::helpUri($finding->rule),
                'help' => ['text' => $finding->remediation],
            ];
""")
replace('src/Console/TransactionGuardCommand.php',
"""                        'region' => ['startLine' => $finding->line],
""",
"""                        'region' => array_filter([
                            'startLine' => $finding->line,
                            'startColumn' => $finding->column,
                            'endColumn' => $finding->endColumn,
                        ], static fn (mixed $value): bool => $value !== null),
""")

# ---------------------------------------------------------------------------
# Config surface.
# ---------------------------------------------------------------------------
replace('config/transaction-guard.php',
"""    'baseline' => '.transaction-guard-baseline.json',

    /* info | warning | error | critical | never */
""",
"""    'baseline' => '.transaction-guard-baseline.json',

    /* Fail instead of reporting a clean scan when no PHP files are discovered. */
    'allow_empty_scan' => false,

    /* info | warning | error | critical | never */
""")

# ---------------------------------------------------------------------------
# Smoke/benchmark autoload lists for RuleCatalog.
# ---------------------------------------------------------------------------
for path in ['tools/smoke.php', 'tools/benchmark.php']:
    replace(path, "    'src/Analysis/Severity.php',\n", "    'src/Analysis/Severity.php',\n    'src/Analysis/RuleCatalog.php',\n")

# ---------------------------------------------------------------------------
# Dedicated v0.3 hardening scenario module (future matrix can keep splitting).
# ---------------------------------------------------------------------------
write('tests/Support/Scenarios/V030Hardening.php', r'''<?php

declare(strict_types=1);

return [
    'object exec method is not PHP exec' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
DB::transaction(function () use ($service) { $service->exec('safe-domain-call'); });
PHP,
        'rules' => [], 'absent' => ['TG009'],
    ],
    'object touch method is not native filesystem touch' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
DB::transaction(function () use ($model) { $model->touch(); });
PHP,
        'rules' => [], 'absent' => ['TG007'],
    ],
    'object defer method is not Laravel defer helper' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
DB::transaction(function () use ($scheduler) { $scheduler->defer(fn () => null); });
PHP,
        'rules' => [], 'absent' => ['TG018'],
    ],
    'conditional local job assignment is not guessed' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
class A implements ShouldQueue {}
class B implements ShouldQueue {}
DB::transaction(function () use ($flag) { if ($flag) { $job = new A(); } else { $job = new B(); } dispatch($job); });
PHP,
        'rules' => ['TG001'],
    ],
    'dynamic notification viaConnections is not accepted as safety proof' => [
        'code' => <<<'PHP'
<?php
namespace App\Notifications;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
class Alert extends Notification implements ShouldQueue { public function viaConnections(): array { return $this->connections(); } private function connections(): array { return []; } }
DB::transaction(function () use ($user) { $user->notify(new Alert()); });
PHP,
        'rules' => ['TG004'],
        'config' => ['queue_default' => 'redis', 'queue_after_commit' => ['redis' => true]],
    ],
    'manual transaction early return is unclosed' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
function run(bool $skip): void { DB::beginTransaction(); if ($skip) { return; } DB::commit(); }
PHP,
        'rules' => ['TG013'],
    ],
    'eloquent saveQuietly on another model connection is reported' => [
        'code' => <<<'PHP'
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
class Audit extends Model { protected $connection = 'pgsql'; }
DB::connection('mysql')->transaction(function () { $audit = new Audit(); $audit->saveQuietly(); });
PHP,
        'rules' => ['TG021'],
        'config' => ['database_default' => 'mysql'],
    ],
    'eloquent update instance on another model connection is reported' => [
        'code' => <<<'PHP'
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
class Audit extends Model { protected $connection = 'pgsql'; }
DB::connection('mysql')->transaction(function () { $audit = new Audit(); $audit->update(['ok' => 1]); });
PHP,
        'rules' => ['TG021'],
        'config' => ['database_default' => 'mysql'],
    ],
    'queue connection enum attribute is resolved' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Attributes\Connection;
use Illuminate\Support\Facades\DB;
enum QueueConnection: string { case Redis = 'redis'; }
#[Connection(QueueConnection::Redis)]
class ProcessOrder implements ShouldQueue {}
DB::transaction(function () { ProcessOrder::dispatch(); });
PHP,
        'rules' => [], 'absent' => ['TG001'],
        'config' => ['queue_default' => 'database', 'queue_after_commit' => ['database' => false, 'redis' => true]],
    ],
];
''')
replace('tests/Support/ScenarioMatrix.php', 'return [\n', '$scenarios = [\n', count=1)
text = read('tests/Support/ScenarioMatrix.php')
pos = text.rfind('];')
if pos < 0:
    raise RuntimeError('unable to find ScenarioMatrix closing array')
text = text[:pos] + "];\n\nreturn array_merge($scenarios, require __DIR__.'/Scenarios/V030Hardening.php');" + text[pos+2:]
write('tests/Support/ScenarioMatrix.php', text)

# ---------------------------------------------------------------------------
# Command/reliability tests.
# ---------------------------------------------------------------------------
write('tests/Feature/V030HardeningTest.php', r'''<?php

declare(strict_types=1);

use Codegenie\TransactionGuard\Analysis\AnalysisConfig;

it('fails parse diagnostics even with fail-on never', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'tg-parse-').'.php';
    file_put_contents($file, '<?php function broken( {');
    try {
        $this->artisan('transaction:guard', ['paths' => [$file], '--fail-on' => 'never'])
            ->assertExitCode(1);
    } finally { @unlink($file); }
});

it('does not generate a baseline while diagnostics exist', function (): void {
    $dir = sys_get_temp_dir().'/tg-diagnostic-baseline-'.bin2hex(random_bytes(4));
    mkdir($dir, 0777, true);
    $file = $dir.'/Broken.php';
    $baseline = $dir.'/baseline.json';
    file_put_contents($file, '<?php function broken( {');
    try {
        $this->artisan('transaction:guard', ['paths' => [$file], '--baseline' => $baseline, '--generate-baseline' => true])
            ->assertExitCode(1);
        expect(is_file($baseline))->toBeFalse();
    } finally { @unlink($file); @unlink($baseline); @rmdir($dir); }
});

it('rejects missing scan paths', function (): void {
    $this->artisan('transaction:guard', ['paths' => [sys_get_temp_dir().'/does-not-exist-'.bin2hex(random_bytes(4))]])
        ->assertExitCode(2);
});

it('rejects unknown disabled rule ids', function (): void {
    expect(fn () => new AnalysisConfig(disabledRules: ['TG0006']))
        ->toThrow(InvalidArgumentException::class);
});

it('explains a canonical rule', function (): void {
    $this->artisan('transaction:guard', ['--explain' => 'TG006'])
        ->expectsOutputToContain('TG006')
        ->expectsOutputToContain('Outbound HTTP')
        ->assertSuccessful();
});

it('resolves an eloquent parent through the composer loader', function (): void {
    $dir = sys_get_temp_dir().'/tg-vendor-parent-'.bin2hex(random_bytes(4));
    mkdir($dir, 0777, true);
    $file = $dir.'/User.php';
    file_put_contents($file, <<<'PHP'
<?php
namespace App\Models;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
class User extends Authenticatable { protected $connection = 'pgsql'; }
DB::connection('mysql')->transaction(function () { User::create(['name' => 'A']); });
PHP);
    try {
        config()->set('database.default', 'mysql');
        $this->artisan('transaction:guard', ['paths' => [$file], '--format' => 'json'])
            ->expectsOutputToContain('TG021')
            ->assertExitCode(1);
    } finally { @unlink($file); @rmdir($dir); }
});
''')

# ---------------------------------------------------------------------------
# Docs consistency gate.
# ---------------------------------------------------------------------------
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
    if (! str_contains($rules, $id) && ! RuleCatalog::isDiagnostic($id)) {
        $failed[] = "docs/RULES.md is missing {$id}";
    }
    if (! str_contains($readme, $id)) {
        $failed[] = "README.md is missing {$id}";
    }
}

if ($failed !== []) {
    fwrite(STDERR, implode("\n", $failed)."\n");
    exit(1);
}

fwrite(STDOUT, "Rule documentation is synchronized.\n");
''')

# composer scripts
import json
composer = json.loads(read('composer.json'))
composer['scripts']['check:docs'] = '@php tools/check-docs.php'
check = composer['scripts']['check']
if '@check:docs' not in check:
    check.insert(check.index('@test:smoke'), '@check:docs')
composer['scripts-descriptions']['check:docs'] = 'Verify the canonical rule catalog is represented in public documentation.'
write('composer.json', json.dumps(composer, indent=4) + '\n')

# ---------------------------------------------------------------------------
# Benchmark: larger and more representative; reset peak usage per workload.
# ---------------------------------------------------------------------------
write('tools/benchmark.php', r'''<?php

declare(strict_types=1);

$root = dirname(__DIR__);
foreach ([
    'src/Analysis/Severity.php',
    'src/Analysis/RuleCatalog.php',
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
] as $file) require_once $root.'/'.$file;

use Codegenie\TransactionGuard\TransactionGuard;

/** @return array{files:int,ms:float,memory:float,findings:int} */
function benchmarkTransactionGuard(string $name, int $files, callable $sourceFactory): array
{
    $directory = sys_get_temp_dir().'/transaction-guard-benchmark-'.$name.'-'.bin2hex(random_bytes(4));
    mkdir($directory, 0777, true);
    try {
        for ($file = 0; $file < $files; $file++) file_put_contents($directory."/Service{$file}.php", $sourceFactory($file));
        if (function_exists('memory_reset_peak_usage')) memory_reset_peak_usage();
        $startMemory = memory_get_usage(true);
        $start = hrtime(true);
        $result = (new TransactionGuard)->analyze([$directory]);
        return ['files' => $result->filesAnalyzed, 'ms' => (hrtime(true) - $start) / 1_000_000,
            'memory' => max(0, memory_get_peak_usage(true) - $startMemory) / 1024 / 1024,
            'findings' => count($result->findings)];
    } finally {
        foreach (glob($directory.'/*.php') ?: [] as $file) @unlink($file);
        @rmdir($directory);
    }
}

$workloads = [
    'transaction-free-1000' => [1000, static fn (int $file): string => "<?php\nnamespace App\\Services; final class Service{$file} { public function run(): int { return 1; } }\n"],
    'safe-transactions-250' => [250, static fn (int $file): string => "<?php\nnamespace App\\Services; use Illuminate\\Support\\Facades\\DB; final class Service{$file} { public function run(): void { DB::transaction(fn () => DB::table('orders')->update(['paid' => true])); } }\n"],
    'side-effect-heavy-250' => [250, static fn (int $file): string => "<?php\nnamespace App\\Services; use Illuminate\\Support\\Facades\\DB; use Illuminate\\Support\\Facades\\Http; final class Service{$file} { public function run(): void { DB::transaction(fn () => Http::post('https://example.test')); } }\n"],
    'metadata-heavy-250' => [250, static fn (int $file): string => "<?php\nnamespace App\\Jobs; use Illuminate\\Contracts\\Queue\\ShouldQueue; class Base{$file} implements ShouldQueue {} class Service{$file} extends Base{$file} {}\n"],
];
foreach ($workloads as $name => [$files, $factory]) {
    $result = benchmarkTransactionGuard($name, $files, $factory);
    printf("%s: %d files in %.2f ms; peak delta %.2f MiB; %d findings.\n", $name, $result['files'], $result['ms'], $result['memory'], $result['findings']);
}
''')

# ---------------------------------------------------------------------------
# Documentation and changelog.
# ---------------------------------------------------------------------------
replace('README.md', "| `TG901` | error | PHP parse failure |", "| `TG901` | error | PHP parse failure |\n| `TG902` | error | Analyzer regex/runtime failure |")
replace('docs/RULES.md', "## TG100 — project custom side effect", "## TG902 — analyzer regular-expression failure\n\nReports a non-baselineable analyzer diagnostic when PCRE fails while evaluating a scanner pattern. This always fails the command, including with `--fail-on=never`, because silently incomplete analysis is unsafe.\n\n## TG100 — project custom side effect")
replace('docs/SCENARIO-MATRIX.md', 'The initial release matrix covers more than one hundred transaction-safety scenarios across these groups:', 'The executable matrix is the source of truth for the exact scenario count. It is split into a core matrix plus focused hardening modules and covers these groups:')
replace('docs/DESIGN.md', "- Full interprocedural data-flow analysis.\n", "- Full interprocedural data-flow analysis. Local inference is deliberately conservative when multiple/conditional reaching assignments are visible.\n")
replace('docs/ANALYSIS.md', "## ", "## Analyzer diagnostics are never suppressible by baselines\n\nTG900/TG901/TG902 represent analyzer integrity failures, not accepted application debt. They are always reported, are never written to baselines, and keep a non-zero exit status even when `fail_on=never`.\n\n## ", count=1)
changelog = read('CHANGELOG.md')
entry = '''## [v0.3.0] - 2026-08-22\n\n### Added\n\n- Canonical rule catalog with rule explanation/help links and documentation consistency checks.\n- Non-baselineable analyzer diagnostics, including TG902 for PCRE execution failures.\n- Lazy Composer class-file metadata resolution for framework/vendor parent classes.\n- Backed-enum resolution for statically known Laravel queue/database connection metadata.\n- Source columns in JSON, GitHub annotations and SARIF output.\n- Focused v0.3 hardening scenario module and broader Eloquent mutation coverage.\n\n### Changed\n\n- Parse/read/analyzer failures always fail CI, including with `--fail-on=never`, and prevent baseline generation.\n- Local variable inference is conservative across multiple or conditional assignments.\n- Global function detection no longer confuses object methods such as `->exec()`, `->touch()` and `->defer()`.\n- Dynamic notification `viaConnections()` results no longer count as proof of commit safety.\n- Manual transaction balance accounts for statically visible early exits.\n- Empty/missing scan paths fail explicitly by default.\n- Baseline output is deterministic and fingerprints are rooted at the configured project path.\n- Benchmarks now cover larger transaction-free, safe, side-effect-heavy and metadata-heavy workloads.\n\n'''
if '## [v0.3.0]' not in changelog:
    idx = changelog.find('## [')
    if idx < 0: raise RuntimeError('CHANGELOG version anchor missing')
    changelog = changelog[:idx] + entry + changelog[idx:]
    write('CHANGELOG.md', changelog)

print('v0.3.0 hardening patch applied')
