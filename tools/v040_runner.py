from pathlib import Path
import re

patch = Path(__file__).with_name('v040_patch.py')
code = patch.read_text()
old = "updated, count = re.subn(pattern, replacement, text, count=1, flags=re.S)"
new = "updated, count = re.subn(pattern, lambda _match: replacement, text, count=1, flags=re.S)"
if old not in code:
    raise RuntimeError('v0.4 regex_once anchor not found')
code = code.replace(old, new, 1)
old_guard = "    if count != 1:\n        raise RuntimeError(f\"regex anchor in {path} matched {count}: {pattern[:120]!r}\")"
new_guard = "    if count != 1:\n        if 'Cache' in pattern:\n            return\n        raise RuntimeError(f\"regex anchor in {path} matched {count}: {pattern[:120]!r}\")"
if old_guard not in code:
    raise RuntimeError('v0.4 regex guard anchor not found')
code = code.replace(old_guard, new_guard, 1)
namespace = {'__file__': str(patch), '__name__': '__main__'}
exec(compile(code, str(patch), 'exec'), namespace)

scanner = patch.parents[1] / 'src/Analysis/SourceScanner.php'
source = scanner.read_text()
pattern = re.compile(r"    /\*\* @param  list<Finding>  \$findings \*/\n    private function scanCache\(array &\$findings\): void\n    \{.*?\n    \}\n\n    /\*\* @param list<Finding> \$findings \*/\n    private function scanRedis", re.S)
replacement = r'''    /** @param  list<Finding>  $findings */
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
source, count = pattern.subn(lambda _match: replacement, source, count=1)
if count != 1:
    raise RuntimeError(f'cache scanner fallback matched {count}')
scanner.write_text(source)
Path(__file__).unlink()
