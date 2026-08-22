from __future__ import annotations

import sys
from pathlib import Path


def replace_once(text: str, old: str, new: str, label: str) -> str:
    if old not in text:
        raise SystemExit(f"{label}: expected source block not found")
    return text.replace(old, new, 1)


def point1() -> None:
    source = Path("src/Analysis/SourceScanner.php")
    text = source.read_text()
    old = """                $metadata = $this->classIndex->metadata($resolved);\n                $method = $this->captured($match, 'method');\n                $statement = $this->statementAt($offset);\n                $looksLikeJob = $metadata?->queued() === true\n                    || str_contains(strtolower($resolved), '\\\\jobs\\\\')\n                    || preg_match('/\\\\\\\\Jobs\\\\\\\\/', $resolved) === 1;\n"""
    new = """                $metadata = $this->classIndex->metadata($resolved);\n                $method = $this->captured($match, 'method');\n                $statement = $this->statementAt($offset);\n                $globalDispatchHelper = preg_match('/(?<![A-Za-z0-9_>])(?<!->)\\\\\\\\?dispatch\\\\s*\\\\(\\\\s*new\\\\s+/i', $statement) === 1;\n                $looksLikeJob = $globalDispatchHelper\n                    || $metadata?->queued() === true\n                    || str_contains(strtolower($resolved), '\\\\jobs\\\\')\n                    || preg_match('/\\\\\\\\Jobs\\\\\\\\/', $resolved) === 1;\n"""
    source.write_text(replace_once(text, old, new, "point1 dispatch"))

    matrix = Path("tests/Support/ScenarioMatrix.php")
    text = matrix.read_text()
    marker = "    'fully qualified DB and Http facades are detected' => [\n"
    scenario = r'''    'global dispatch helper detects job outside Jobs namespace' => [
        'code' => <<<'PHP'
<?php
namespace App\Work;
use Illuminate\Support\Facades\DB;
class RecalculateOrder {}
DB::transaction(function () { dispatch(new RecalculateOrder()); });
PHP,
        'rules' => ['TG001'],
    ],
    'global dispatch helper outside Jobs namespace is safe after commit' => [
        'code' => <<<'PHP'
<?php
namespace App\Work;
use Illuminate\Support\Facades\DB;
class RecalculateOrder {}
DB::transaction(function () { dispatch(new RecalculateOrder())->afterCommit(); });
PHP,
        'rules' => [],
        'absent' => ['TG001'],
    ],
'''
    if "global dispatch helper detects job outside Jobs namespace" in text:
        raise SystemExit("point1 scenarios already present")
    matrix.write_text(replace_once(text, marker, scenario + marker, "point1 scenario marker"))


def point2() -> None:
    meta = Path("src/Analysis/ClassMetadataIndex.php")
    text = meta.read_text()
    signature = "    private function queueNameFor(string $class, array $seen = []): ?string\n"
    doc = "    /** @param  array<string, true>  $seen */\n"
    if doc + signature not in text:
        text = replace_once(text, signature, doc + signature, "point2 queueNameFor")
    meta.write_text(text)

    source = Path("src/Analysis/SourceScanner.php")
    text = source.read_text()
    start = text.index("    private function scanNotifications")
    end = text.index("    private function scanBroadcasts", start)
    section = text[start:end]
    old_nullsafe = "$metadata?->queueAfterCommit()"
    if old_nullsafe not in section:
        raise SystemExit("point2 notification nullsafe target not found")
    section = section.replace(old_nullsafe, "$metadata->queueAfterCommit()", 1)
    text = text[:start] + section + text[end:]

    old_flush = '''            $flush = function () use (&$regions, &$groupStart, &$groupEnd, &$depth): void {
                if ($groupStart === null) {
                    return;
                }

                $end = $groupEnd ?? strlen($this->source);
                $regions[] = [
                    'start' => $groupStart['end'],
                    'end' => $end,
                    'line' => $this->lineAtOffset($groupStart['offset']),
                    'type' => 'manual',
                    'attempts' => 1,
                    'connection' => $groupStart['connection'],
                    'callableStart' => $groupStart['end'],
                    'callableEnd' => $end,
                ];

                $groupStart = null;
                $groupEnd = null;
                $depth = 0;
            };
'''
    new_flush = '''            /** @param DatabaseControlCall|null $start */
            $flush = function (?array $start, ?int $endOffset) use (&$regions): void {
                if ($start === null) {
                    return;
                }

                $end = $endOffset ?? strlen($this->source);
                $regions[] = [
                    'start' => $start['end'],
                    'end' => $end,
                    'line' => $this->lineAtOffset($start['offset']),
                    'type' => 'manual',
                    'attempts' => 1,
                    'connection' => $start['connection'],
                    'callableStart' => $start['end'],
                    'callableEnd' => $end,
                ];
            };
'''
    text = replace_once(text, old_flush, new_flush, "point2 manual transaction flush")
    text = replace_once(
        text,
        "                        $flush();\n                    }\n                    if ($groupStart === null) {",
        "                        $flush($groupStart, $groupEnd);\n                        $groupStart = null;\n                        $groupEnd = null;\n                        $depth = 0;\n                    }\n                    if ($groupStart === null) {",
        "point2 nested manual flush call",
    )
    text = replace_once(
        text,
        "            $flush();\n        }\n\n        return $regions;",
        "            $flush($groupStart, $groupEnd);\n        }\n\n        return $regions;",
        "point2 final manual flush call",
    )

    old_captured = '''        $value = $match['matches'][$name] ?? '';
        if (is_array($value)) {
            return (string) $value[0];
        }

        return (string) $value;
'''
    new_captured = '''        $value = $match['matches'][$name] ?? '';
        if (is_array($value)) {
            $captured = $value[0] ?? '';

            return is_string($captured) ? $captured : '';
        }

        return is_string($value) ? $value : '';
'''
    text = replace_once(text, old_captured, new_captured, "point2 captured value narrowing")
    source.write_text(text)

    Path("phpstan.neon").write_text(
        "includes:\n"
        "    - vendor/larastan/larastan/extension.neon\n\n"
        "parameters:\n"
        "    level: max\n"
        "    paths:\n"
        "        - src\n"
        "    tmpDir: .phpstan-cache\n"
        "    reportUnmatchedIgnoredErrors: true\n"
    )


def point3() -> None:
    source = Path("src/Analysis/SourceScanner.php")
    text = source.read_text()
    old = "        $this->transactions = array_merge($this->findClosureTransactions(), $this->findManualTransactions());\n        $this->afterCommitCallbacks = $this->findAfterCommitCallbacks();\n"
    new = "        $this->transactions = array_merge($this->findClosureTransactions(), $this->findManualTransactions());\n        if ($this->transactions === []) {\n            return [];\n        }\n\n        $this->afterCommitCallbacks = $this->findAfterCommitCallbacks();\n"
    source.write_text(replace_once(text, old, new, "point3 transaction fast path"))


def point4() -> None:
    baseline = Path("src/Analysis/Baseline.php")
    text = baseline.read_text()
    old = '''        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            return new self;
        }

        $fingerprintValue = $decoded['fingerprints'] ?? null;
        $fingerprints = is_array($fingerprintValue) ? $fingerprintValue : [];

        return new self(array_values(array_filter($fingerprints, 'is_string')));
'''
    new = '''        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new \\RuntimeException("Invalid Transaction Guard baseline [{$path}]: expected a JSON object.");
        }

        $version = $decoded['version'] ?? null;
        if ($version !== 1) {
            throw new \\RuntimeException("Invalid Transaction Guard baseline [{$path}]: unsupported or missing version.");
        }

        $fingerprints = $decoded['fingerprints'] ?? null;
        if (! is_array($fingerprints)) {
            throw new \\RuntimeException("Invalid Transaction Guard baseline [{$path}]: fingerprints must be an array.");
        }

        foreach ($fingerprints as $fingerprint) {
            if (! is_string($fingerprint) || $fingerprint === '') {
                throw new \\RuntimeException("Invalid Transaction Guard baseline [{$path}]: every fingerprint must be a non-empty string.");
            }
        }

        return new self(array_values($fingerprints));
'''
    baseline.write_text(replace_once(text, old, new, "point4 baseline schema"))

    tests = Path("tests/Unit/BaselineTest.php")
    text = tests.read_text()
    addition = '''

it('rejects structurally invalid baselines', function (string $json): void {
    $path = sys_get_temp_dir().'/invalid-transaction-guard-baseline-'.bin2hex(random_bytes(4)).'.json';

    try {
        file_put_contents($path, $json);
        expect(fn () => Baseline::load($path))->toThrow(RuntimeException::class);
    } finally {
        @unlink($path);
    }
})->with([
    'null root' => 'null',
    'missing version' => '{"fingerprints":[]}',
    'wrong version' => '{"version":2,"fingerprints":[]}',
    'fingerprints not array' => '{"version":1,"fingerprints":"oops"}',
    'non-string fingerprint' => '{"version":1,"fingerprints":[123]}',
    'empty fingerprint' => '{"version":1,"fingerprints":[""]}',
]);
'''
    if "rejects structurally invalid baselines" in text:
        raise SystemExit("point4 baseline regression test already present")
    tests.write_text(text.rstrip() + addition)


POINTS = {
    "audit/01-global-dispatch": point1,
    "audit/02-phpstan-zero-ignores": point2,
    "audit/03-fast-path": point3,
    "audit/04-baseline-schema": point4,
}

if len(sys.argv) != 2 or sys.argv[1] not in POINTS:
    raise SystemExit("unsupported audit branch")

POINTS[sys.argv[1]]()
Path(".audit-request").unlink(missing_ok=True)
