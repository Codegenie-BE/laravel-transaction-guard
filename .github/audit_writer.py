from __future__ import annotations

import subprocess
import sys
from pathlib import Path

BRANCH = "audit/08-constructor-queue-metadata"
if len(sys.argv) != 2 or sys.argv[1] != BRANCH:
    raise SystemExit("unsupported audit branch")

metadata = Path("src/Analysis/ClassMetadata.php")
text = metadata.read_text()
old = """        public ?string $constructorQueueConnection = null,\n        public ?string $queueConnectionAttribute = null,\n"""
new = """        public ?string $constructorQueueConnection = null,\n        public bool $declaresConstructor = false,\n        public ?string $queueConnectionAttribute = null,\n"""
if old not in text:
    raise SystemExit("metadata marker missing")
metadata.write_text(text.replace(old, new, 1))

index = Path("src/Analysis/ClassMetadataIndex.php")
text = index.read_text()
old = """            [$afterCommit, $beforeCommit, $constructorConnection, $constructorQueue, $constructorOverride] =\n                $this->constructorQueueBehavior($tokens, $openBrace + 1, $closeBrace - 1, $source);\n"""
new = """            [$afterCommit, $beforeCommit, $constructorConnection, $constructorQueue, $constructorOverride, $declaresConstructor] =\n                $this->constructorQueueBehavior($tokens, $openBrace + 1, $closeBrace - 1, $source);\n"""
if old not in text:
    raise SystemExit("constructor tuple marker missing")
text = text.replace(old, new, 1)
old = """                constructorQueueConnection: $queueConnection,\n                queueConnectionAttribute: $attributeConnection,\n"""
new = """                constructorQueueConnection: $queueConnection,\n                declaresConstructor: $declaresConstructor,\n                queueConnectionAttribute: $attributeConnection,\n"""
if old not in text:
    raise SystemExit("metadata construction marker missing")
text = text.replace(old, new, 1)

start = text.index("    /**\n     * @param  list<Token>  $tokens\n     * @return array{bool,bool,string|null,string|null,bool|null}\n     */\n    private function constructorQueueBehavior")
end = text.index("    /**\n     * @return list<array{offset:int,value:bool}>\n     */\n    private function booleanQueuePreferenceMatches", start)
replacement = r'''    /**
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

'''
text = text[:start] + replacement + text[end:]
text = text.replace("'/->\\s*afterCommit\\s*\\(/' => true,", "'/\\$this\\s*->\\s*afterCommit\\s*\\(/' => true,", 1)
text = text.replace("'/->\\s*beforeCommit\\s*\\(/' => false,", "'/\\$this\\s*->\\s*beforeCommit\\s*\\(/' => false,", 1)

old = """                constructorQueueConnection: $metadata->constructorQueueConnection,\n                queueConnectionAttribute: $metadata->queueConnectionAttribute,\n"""
new = """                constructorQueueConnection: $metadata->constructorQueueConnection,\n                declaresConstructor: $metadata->declaresConstructor,\n                queueConnectionAttribute: $metadata->queueConnectionAttribute,\n"""
if old not in text:
    raise SystemExit("metadata rebuild marker missing")
text = text.replace(old, new, 1)

old = """        $index->resolveInheritedInterfaces();\n\n        return $index;\n"""
new = """        $index->resolveInheritedInterfaces();\n        $index->resolveInheritedConstructorBehavior();\n\n        return $index;\n"""
if old not in text:
    raise SystemExit("index finalization marker missing")
text = text.replace(old, new, 1)

marker = """    /**\n     * @param  array<string, true>  $seen\n     * @return list<string>\n     */\n    private function inheritedInterfacesForClass"""
helper = r'''    private function resolveInheritedConstructorBehavior(): void
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
        );
    }

'''
if marker not in text:
    raise SystemExit("inheritance marker missing")
text = text.replace(marker, helper + marker, 1)
index.write_text(text)

matrix = Path("tests/Support/ScenarioMatrix.php")
text = matrix.read_text()
marker = "    'job constructor queue connection unsafe override is respected' => [\n"
scenarios = r'''    'afterCommit on another object does not configure the job' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
class ProcessOrder implements ShouldQueue { public function __construct($other) { $other->afterCommit(); } }
DB::transaction(function () { ProcessOrder::dispatch(new \stdClass()); });
PHP,
        'rules' => ['TG001'],
    ],
    'conditional constructor afterCommit is not trusted as unconditional' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
class ProcessOrder implements ShouldQueue { public function __construct(bool $safe) { if ($safe) { $this->afterCommit(); } } }
DB::transaction(function () { ProcessOrder::dispatch(false); });
PHP,
        'rules' => ['TG001'],
    ],
    'child without constructor inherits parent afterCommit behavior' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
class BaseJob implements ShouldQueue { public function __construct() { $this->afterCommit(); } }
class ProcessOrder extends BaseJob {}
DB::transaction(function () { ProcessOrder::dispatch(); });
PHP,
        'rules' => [],
        'absent' => ['TG001'],
    ],
    'child constructor does not inherit parent constructor afterCommit behavior' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
class BaseJob implements ShouldQueue { public function __construct() { $this->afterCommit(); } }
class ProcessOrder extends BaseJob { public function __construct() {} }
DB::transaction(function () { ProcessOrder::dispatch(); });
PHP,
        'rules' => ['TG001'],
    ],
'''
if marker not in text:
    raise SystemExit("scenario marker missing")
matrix.write_text(text.replace(marker, scenarios + marker, 1))

Path(".audit-request").unlink(missing_ok=True)
base = subprocess.run(["git", "show", "origin/main:.github/audit_writer.py"], check=True, capture_output=True, text=True).stdout
Path(".github/audit_writer.py").write_text(base)
