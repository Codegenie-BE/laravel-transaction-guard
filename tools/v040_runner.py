from pathlib import Path
import re

patch = Path(__file__).with_name('v040_patch.py')
code = patch.read_text()

# Preserve PHP replacement strings literally instead of letting Python re.sub
# interpret backslashes from PHP namespaces as replacement escapes.
old = "updated, count = re.subn(pattern, replacement, text, count=1, flags=re.S)"
new = "updated, count = re.subn(pattern, lambda _match: replacement, text, count=1, flags=re.S)"
if old not in code:
    raise RuntimeError('v0.4 regex_once anchor not found')
code = code.replace(old, new, 1)

# Cache and Redis are replaced structurally below because their v0.3 method
# bodies have evolved enough that exact historical regex anchors are brittle.
old_guard = "    if count != 1:\n        raise RuntimeError(f\"regex anchor in {path} matched {count}: {pattern[:120]!r}\")"
new_guard = "    if count != 1:\n        if 'Cache' in pattern or '$commandPattern' in pattern:\n            return\n        raise RuntimeError(f\"regex anchor in {path} matched {count}: {pattern[:120]!r}\")"
if old_guard not in code:
    raise RuntimeError('v0.4 regex guard anchor not found')
code = code.replace(old_guard, new_guard, 1)

# The old Redis mutation allowlist is also replaced structurally below. Let the
# deterministic patch proceed when that exact historical string no longer
# matches the current scanner source.
old_replace_guard = "    if old not in text:\n        raise RuntimeError(f\"missing anchor in {path}: {old[:160]!r}\")"
new_replace_guard = "    if old not in text:\n        if \"$mutating = 'set|setex\" in old:\n            return\n        raise RuntimeError(f\"missing anchor in {path}: {old[:160]!r}\")"
if old_replace_guard not in code:
    raise RuntimeError('v0.4 replace_once guard anchor not found')
code = code.replace(old_replace_guard, new_replace_guard, 1)

namespace = {'__file__': str(patch), '__name__': '__main__'}
exec(compile(code, str(patch), 'exec'), namespace)

scanner = patch.parents[1] / 'src/Analysis/SourceScanner.php'
source = scanner.read_text()

# Replace the complete cache scanner and insert RateLimiter analysis.
cache_pattern = re.compile(r"    /\*\* @param  list<Finding>  \$findings \*/\n    private function scanCache\(array &\$findings\): void\n    \{.*?\n    \}\n\n    /\*\* @param list<Finding> \$findings \*/\n    private function scanRedis", re.S)
cache_replacement = r'''    /** @param  list<Finding>  $findings */
    private function scanCache(array &$findings): void
    {
        if (! $this->sourceContainsAny(['cache'])) {
            return;
        }

        foreach ($this->facadeAliases('Illuminate\\Support\\Facades\\Cache', 'Cache') as $alias) {
            $methods = OperationCatalog::alternation(OperationCatalog::CACHE_MUTATIONS);
            $pattern = '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::(?:(?!;).)*?\b(?P<method>'.$methods.')\s*\(/is';
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
            $lockPattern = '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::\s*(?:lock|restoreLock)\s*\((?:(?!;).)*?\)\s*->\s*(?P<method>'.$lockTerminals.')\s*\(/is';
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
            foreach ($this->matches('/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::\s*(?P<method>'.$methods.')\s*\(/i') as $match) {
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
    private function scanRedis'''
source, count = cache_pattern.subn(lambda _match: cache_replacement, source, count=1)
if count != 1:
    raise RuntimeError(f'cache scanner fallback matched {count}')

# Replace the complete Redis scanner. Known reads are ignored, known mutations
# are reported, script/unknown commands remain conservative, and inline
# pipeline/transaction callbacks are inspected instead of flagging the wrapper.
redis_pattern = re.compile(r"    /\*\* @param list<Finding> \$findings \*/\n    private function scanRedis\(array &\$findings\): void\n    \{.*?\n    \}\n(?:\n    /\*\* @return array\{bool,bool\} mutates, unknown \*/\n    private function redisCallbackMutationState\(string \$statement\): array\n    \{.*?\n    \}\n)?\n    /\*\* @param  list<Finding>  \$findings \*/\n    private function scanProcesses", re.S)
redis_replacement = r'''    /** @param list<Finding> $findings */
    private function scanRedis(array &$findings): void
    {
        if (! $this->sourceContainsAny(['redis'])) {
            return;
        }

        $mutating = OperationCatalog::alternation(OperationCatalog::REDIS_MUTATIONS);

        foreach ($this->facadeAliases('Illuminate\\Support\\Facades\\Redis', 'Redis') as $alias) {
            $pattern = '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::(?:(?!;).)*?\b(?P<method>'.$mutating.')\s*\(/is';
            foreach ($this->matches($pattern) as $match) {
                $offset = $match['offset'];
                $tx = $this->eligibleTransaction($offset);
                if ($tx === null) {
                    continue;
                }

                $method = strtolower($this->captured($match, 'method'));
                $severity = $method === 'publish' ? Severity::Error : Severity::Warning;
                $this->appendFinding($findings, $offset, 'TG020', $severity,
                    "Redis::{$method}() mutates non-transactional state while a database transaction is open.",
                    'Move the Redis mutation after commit, or use an idempotent/outbox strategy when both systems must remain consistent.', 'high');
                $this->appendRetryFinding($findings, $offset, $tx, "Redis {$method}");
            }

            $commandPattern = '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::\s*command\s*\(\s*[\'\"](?P<command>[A-Za-z0-9_]+)[\'\"]/i';
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

            foreach ($this->matches('/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::\s*(?P<method>pipeline|transaction)\s*\(/i') as $match) {
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
        if (preg_match('/->\s*(?:'.$mutations.')\s*\(/i', $code) === 1) {
            return [true, false];
        }

        $hasInlineCallable = preg_match('/(?:pipeline|transaction)\s*\(\s*(?:static\s+)?(?:function|fn)\b/i', $code) === 1;

        return [false, ! $hasInlineCallable];
    }

    /** @param  list<Finding>  $findings */
    private function scanProcesses'''
source, count = redis_pattern.subn(lambda _match: redis_replacement, source, count=1)
if count != 1:
    raise RuntimeError(f'Redis scanner fallback matched {count}')

scanner.write_text(source)
Path(__file__).unlink()
