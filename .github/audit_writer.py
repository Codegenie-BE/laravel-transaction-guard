from __future__ import annotations

import subprocess
import sys
from pathlib import Path

BRANCH = "audit/06-eager-nested-callbacks"
if len(sys.argv) != 2 or sys.argv[1] != BRANCH:
    raise SystemExit("unsupported audit branch")

source = Path("src/Analysis/SourceScanner.php")
text = source.read_text()
old = """            $tail = ltrim(substr($this->source, $callable['end'], 24));\n            if (str_starts_with($tail, '(') || preg_match('/^}\\s*\\)?\\s*\\(/', $tail) === 1) {\n                return false; // Immediately invoked closure.\n            }\n\n            return true;\n"""
new = """            $tail = ltrim(substr($this->source, $callable['end'], 24));\n            if (str_starts_with($tail, '(') || preg_match('/^}\\s*\\)?\\s*\\(/', $tail) === 1) {\n                return false; // Immediately invoked closure.\n            }\n            if ($this->nestedCallableRunsEagerly($callable)) {\n                return false;\n            }\n\n            return true;\n"""
if old not in text:
    raise SystemExit("deferred callable block not found")
text = text.replace(old, new, 1)
marker = """    private function isInsideAfterCommitCallback(int $offset): bool\n"""
helper = r'''    /** @param array{start:int,end:int} $callable */
    private function nestedCallableRunsEagerly(array $callable): bool
    {
        $token = $this->tokenIndexBeforeOffset($callable['start']);
        if ($token === null) {
            return false;
        }

        $callableToken = null;
        for ($i = $token; $i >= 0; $i--) {
            if (in_array($this->tokens[$i]['id'], [T_FUNCTION, T_FN], true)) {
                $callableToken = $i;
                break;
            }
            if ($this->tokens[$i]['text'] === ';') {
                break;
            }
        }
        if ($callableToken === null) {
            return false;
        }

        $depth = 0;
        $open = null;
        for ($i = $callableToken - 1; $i >= 0; $i--) {
            $text = $this->tokens[$i]['text'];
            if ($text === ')') {
                $depth++;
                continue;
            }
            if ($text !== '(') {
                continue;
            }
            if ($depth > 0) {
                $depth--;
                continue;
            }
            $open = $i;
            break;
        }
        if ($open === null) {
            return false;
        }

        $nameIndex = $this->previousSignificantToken($open - 1);
        if ($nameIndex === null) {
            return false;
        }
        $rawName = ltrim($this->tokens[$nameIndex]['text'], '\\');
        $parts = explode('\\', $rawName);
        $name = strtolower(end($parts) ?: $rawName);
        if (! in_array($name, [
            'tap', 'retry', 'array_map', 'array_filter', 'array_reduce',
            'array_walk', 'array_walk_recursive', 'usort', 'uasort', 'uksort',
        ], true)) {
            return false;
        }

        $beforeName = $this->previousSignificantToken($nameIndex - 1);

        return $beforeName === null || ! in_array($this->tokens[$beforeName]['id'], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true);
    }

'''
if marker not in text:
    raise SystemExit("after-commit marker not found")
text = text.replace(marker, helper + marker, 1)
source.write_text(text)

matrix = Path("tests/Support/ScenarioMatrix.php")
text = matrix.read_text()
marker = "    'IIFE side effect is detected' => [\n"
scenarios = r'''    'tap callback executes eagerly inside transaction' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
DB::transaction(function () { tap('value', function () { Http::post('https://example.test/capture'); }); });
PHP,
        'rules' => ['TG006'],
    ],
    'retry callback executes eagerly inside transaction' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
DB::transaction(function () { retry(2, function () { Http::post('https://example.test/capture'); }); });
PHP,
        'rules' => ['TG006'],
    ],
    'array map callback executes eagerly inside transaction' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
DB::transaction(function () { array_map(function ($id) { Http::post('https://example.test/capture'); }, [1]); });
PHP,
        'rules' => ['TG006'],
    ],
    'assigned nested closure remains deferred' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
DB::transaction(function () { $later = function () { Http::post('https://example.test/capture'); }; });
PHP,
        'rules' => [],
        'absent' => ['TG006'],
    ],
'''
if marker not in text:
    raise SystemExit("scenario marker not found")
text = text.replace(marker, scenarios + marker, 1)
matrix.write_text(text)

Path(".audit-request").unlink(missing_ok=True)
base = subprocess.run(["git", "show", "origin/main:.github/audit_writer.py"], check=True, capture_output=True, text=True).stdout
Path(".github/audit_writer.py").write_text(base)
