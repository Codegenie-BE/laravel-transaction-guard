from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[1]
PATCH = ROOT / 'tools/v040_patch.py'
SCANNER = ROOT / 'src/Analysis/SourceScanner.php'

code = PATCH.read_text()

# Python must not interpret PHP namespace backslashes as replacement escapes.
old = "updated, count = re.subn(pattern, replacement, text, count=1, flags=re.S)"
new = "updated, count = re.subn(pattern, lambda _match: replacement, text, count=1, flags=re.S)"
if old not in code:
    raise RuntimeError('regex_once replacement anchor missing')
code = code.replace(old, new, 1)

# Cache and Redis scanner bodies are normalized structurally after the main
# deterministic patch. Historical method-body anchors may safely be skipped.
old = "    if count != 1:\n        raise RuntimeError(f\"regex anchor in {path} matched {count}: {pattern[:120]!r}\")"
new = "    if count != 1:\n        if 'Cache' in pattern or '$commandPattern' in pattern:\n            return\n        raise RuntimeError(f\"regex anchor in {path} matched {count}: {pattern[:120]!r}\")"
if old not in code:
    raise RuntimeError('regex_once guard anchor missing')
code = code.replace(old, new, 1)

old = "    if old not in text:\n        raise RuntimeError(f\"missing anchor in {path}: {old[:160]!r}\")"
new = "    if old not in text:\n        if \"$mutating = 'set|setex\" in old:\n            return\n        raise RuntimeError(f\"missing anchor in {path}: {old[:160]!r}\")"
if old not in code:
    raise RuntimeError('replace_once guard anchor missing')
code = code.replace(old, new, 1)

# Preserve the existing v0.3 scenario module and append v0.4 alongside it.
old = '''# Scenario matrix aggregates v0.4 module without re-expanding the legacy fixture.
text = read('tests/Support/ScenarioMatrix.php')
if "Scenarios/V040Hardening.php" not in text:
    if not text.rstrip().endswith('return $scenarios;'):
        raise RuntimeError('ScenarioMatrix return anchor not found')
    text = text.replace('return $scenarios;', "$scenarios = array_merge($scenarios, require __DIR__.'/Scenarios/V040Hardening.php');\\n\\nreturn $scenarios;")
    write('tests/Support/ScenarioMatrix.php', text)
'''
new = '''# Scenario matrix aggregates v0.4 beside the existing v0.3 module.
text = read('tests/Support/ScenarioMatrix.php')
if "Scenarios/V040Hardening.php" not in text:
    v030_footer = "return array_merge($scenarios, require __DIR__.'/Scenarios/V030Hardening.php');"
    if v030_footer in text:
        text = text.replace(v030_footer, "return array_merge(\\n    $scenarios,\\n    require __DIR__.'/Scenarios/V030Hardening.php',\\n    require __DIR__.'/Scenarios/V040Hardening.php',\\n);")
    elif text.rstrip().endswith('return $scenarios;'):
        text = text.replace('return $scenarios;', "$scenarios = array_merge($scenarios, require __DIR__.'/Scenarios/V040Hardening.php');\\n\\nreturn $scenarios;")
    else:
        raise RuntimeError('ScenarioMatrix return anchor not found')
    write('tests/Support/ScenarioMatrix.php', text)
'''
if old not in code:
    raise RuntimeError('scenario aggregation anchor missing')
code = code.replace(old, new, 1)

namespace = {'__file__': str(PATCH), '__name__': '__main__'}
exec(compile(code, str(PATCH), 'exec'), namespace)

source = SCANNER.read_text()


def method_region(source_text: str, method: str, next_method: str) -> tuple[int, int]:
    method_pos = source_text.find(f'    private function {method}')
    if method_pos < 0:
        raise RuntimeError(f'{method} method not found')
    start = source_text.rfind('    /**', 0, method_pos)
    if start < 0:
        start = method_pos

    next_pos = source_text.find(f'    private function {next_method}', method_pos)
    if next_pos < 0:
        raise RuntimeError(f'{next_method} method not found after {method}')
    end = source_text.rfind('    /**', method_pos, next_pos)
    if end < method_pos:
        end = next_pos

    return start, end


if 'private function scanRateLimiter' not in source:
    start, end = method_region(source, 'scanCache', 'scanRedis')
    cache_code = r'''    /** @param  list<Finding>  $findings */
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

'''
    source = source[:start] + cache_code + source[end:]

if 'OperationCatalog::redisCommandKind' not in source:
    start, end = method_region(source, 'scanRedis', 'scanProcesses')
    redis_code = r'''    /** @param list<Finding> $findings */
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

'''
    source = source[:start] + redis_code + source[end:]

SCANNER.write_text(source)

# The final working tree must not retain any deterministic patch runners.
for relative in [
    'tools/v040_runner.py',
    'tools/v040_runner2.py',
    'tools/v040_runner3.py',
    'tools/v040_final_runner.py',
]:
    target = ROOT / relative
    if target.exists():
        target.unlink()
