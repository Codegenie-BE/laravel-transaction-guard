from __future__ import annotations

import subprocess
import sys
from pathlib import Path

BRANCH = "audit/07-laravel13-connection-attribute"
if len(sys.argv) != 2 or sys.argv[1] != BRANCH:
    raise SystemExit("unsupported audit branch")

metadata = Path("src/Analysis/ClassMetadata.php")
text = metadata.read_text()
old = """        public ?string $constructorQueueConnection = null,\n        public array $traits = [],\n"""
new = """        public ?string $constructorQueueConnection = null,\n        public ?string $queueConnectionAttribute = null,\n        public array $traits = [],\n"""
if old not in text:
    raise SystemExit("ClassMetadata constructor marker not found")
metadata.write_text(text.replace(old, new, 1))

index = Path("src/Analysis/ClassMetadataIndex.php")
text = index.read_text()
marker = """    public function queueRouteConnection(string $class): ?string\n    {\n"""
method = r'''    public function queueConnection(string $class, ?string $instanceConnection = null): ?string
    {
        $attribute = $this->queueConnectionAttributeFor($class);
        if ($attribute !== null) {
            return $attribute;
        }
        if ($instanceConnection !== null) {
            return $instanceConnection;
        }

        return $this->queueConfiguredConnectionFor($class) ?? $this->queueRouteConnection($class);
    }

'''
if marker not in text:
    raise SystemExit("queueRouteConnection marker not found")
text = text.replace(marker, method + marker, 1)

old = """            $attributeQueue = $this->queueAttributeForDeclaration($source, $tokens[$i]['offset'], $context);\n            $queueConnection = $constructorConnection ?? $propertyConnection;\n            $queueName = $constructorQueue ?? $attributeQueue ?? $propertyQueue;\n"""
new = """            $attributeQueue = $this->queueAttributeForDeclaration($source, $tokens[$i]['offset'], $context);\n            $attributeConnection = $this->connectionAttributeForDeclaration($source, $tokens[$i]['offset'], $context);\n            $queueConnection = $constructorConnection ?? $propertyConnection;\n            $queueName = $constructorQueue ?? $attributeQueue ?? $propertyQueue;\n"""
if old not in text:
    raise SystemExit("class metadata creation prelude not found")
text = text.replace(old, new, 1)
old = """                constructorQueueConnection: $queueConnection,\n                traits: $traits,\n"""
new = """                constructorQueueConnection: $queueConnection,\n                queueConnectionAttribute: $attributeConnection,\n                traits: $traits,\n"""
if old not in text:
    raise SystemExit("class metadata argument marker not found")
text = text.replace(old, new, 1)

reference = subprocess.run(["git", "show", "origin/improve/03-connection-attribute:src/Analysis/ClassMetadataIndex.php"], check=True, capture_output=True, text=True).stdout
ref_start = reference.index("    private function queueAttributeForDeclaration(")
ref_end = reference.index("    /**\n     * @param  list<Token>  $tokens\n     */\n    private function parseContext", ref_start)
replacement = reference[ref_start:ref_end]
start = text.index("    private function queueAttributeForDeclaration(")
end = text.index("    /**\n     * @param  list<Token>  $tokens\n     */\n    private function parseContext", start)
text = text[:start] + replacement + text[end:]

old = """                constructorQueueConnection: $metadata->constructorQueueConnection,\n                traits: $metadata->traits,\n"""
new = """                constructorQueueConnection: $metadata->constructorQueueConnection,\n                queueConnectionAttribute: $metadata->queueConnectionAttribute,\n                traits: $metadata->traits,\n"""
if old not in text:
    raise SystemExit("inherited metadata marker not found")
text = text.replace(old, new, 1)

marker = """    private function queueNameFor(string $class, array $seen = []): ?string\n"""
helpers = r'''    /** @param array<string, true> $seen */
    private function queueConnectionAttributeFor(string $class, array $seen = []): ?string
    {
        $key = strtolower(ltrim($class, '\\'));
        if (isset($seen[$key])) {
            return null;
        }
        $seen[$key] = true;
        $metadata = $this->classes[$key] ?? null;
        if ($metadata === null) {
            return null;
        }
        if ($metadata->queueConnectionAttribute !== null) {
            return $metadata->queueConnectionAttribute;
        }

        return $metadata->parent !== null ? $this->queueConnectionAttributeFor($metadata->parent, $seen) : null;
    }

    /** @param array<string, true> $seen */
    private function queueConfiguredConnectionFor(string $class, array $seen = []): ?string
    {
        $key = strtolower(ltrim($class, '\\'));
        if (isset($seen[$key])) {
            return null;
        }
        $seen[$key] = true;
        $metadata = $this->classes[$key] ?? null;
        if ($metadata === null) {
            return null;
        }
        if ($metadata->constructorQueueConnection !== null) {
            return $metadata->constructorQueueConnection;
        }

        return $metadata->parent !== null ? $this->queueConfiguredConnectionFor($metadata->parent, $seen) : null;
    }

    /** @param array<string, true> $seen */
'''
if marker not in text:
    raise SystemExit("queueNameFor marker not found")
text = text.replace(marker, helpers + marker, 1)
index.write_text(text)

scanner = Path("src/Analysis/SourceScanner.php")
text = scanner.read_text()
old = """    private function queueConnectionFromStatement(string $statement, ?ClassMetadata $metadata = null): ?string\n    {\n        $code = $this->codeOnlyFragment($statement);\n        if (preg_match('/->\\s*onConnection\\s*\\(/i', $code, $call, PREG_OFFSET_CAPTURE) === 1) {\n            $literal = $this->literalStringArgumentFromCall(substr($statement, $call[0][1]));\n\n            return $literal ?? '@dynamic';\n        }\n\n        foreach ($this->facadeAliases('Illuminate\\Support\\Facades\\Queue', 'Queue') as $alias) {\n"""
new = """    private function queueConnectionFromStatement(string $statement, ?ClassMetadata $metadata = null): ?string\n    {\n        $code = $this->codeOnlyFragment($statement);\n        $instanceConnection = null;\n        if (preg_match('/->\\s*onConnection\\s*\\(/i', $code, $call, PREG_OFFSET_CAPTURE) === 1) {\n            $instanceConnection = $this->literalStringArgumentFromCall(substr($statement, $call[0][1])) ?? '@dynamic';\n        }\n\n        foreach ($this->facadeAliases('Illuminate\\Support\\Facades\\Queue', 'Queue') as $alias) {\n"""
if old not in text:
    raise SystemExit("queue connection statement prelude not found")
text = text.replace(old, new, 1)
old = """        if ($metadata?->constructorQueueConnection !== null) {\n            return $metadata->constructorQueueConnection;\n        }\n\n        return $metadata === null ? null : $this->classIndex->queueRouteConnection($metadata->name);\n"""
new = """        if ($metadata !== null) {\n            return $this->classIndex->queueConnection($metadata->name, $instanceConnection);\n        }\n\n        return $instanceConnection;\n"""
if old not in text:
    raise SystemExit("queue connection statement tail not found")
scanner.write_text(text.replace(old, new, 1))

matrix = Path("tests/Support/ScenarioMatrix.php")
text = matrix.read_text()
marker = "    'Laravel 13 Queue route safe connection is respected' => [\n"
scenarios = r'''    'Laravel 13 Connection attribute unsafe connection overrides safe default' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Attributes\Connection;
use Illuminate\Support\Facades\DB;
#[Connection('database')]
class ProcessOrder implements ShouldQueue {}
DB::transaction(function () { ProcessOrder::dispatch(); });
PHP,
        'rules' => ['TG001'],
        'config' => ['queue_default' => 'redis', 'queue_after_commit' => ['redis' => true, 'database' => false]],
    ],
    'Laravel 13 Connection attribute safe connection overrides unsafe default' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Attributes\Connection;
use Illuminate\Support\Facades\DB;
#[Connection('redis')]
class ProcessOrder implements ShouldQueue {}
DB::transaction(function () { ProcessOrder::dispatch(); });
PHP,
        'rules' => [],
        'absent' => ['TG001'],
        'config' => ['queue_default' => 'database', 'queue_after_commit' => ['redis' => true, 'database' => false]],
    ],
    'Laravel 13 Connection attribute wins over onConnection property' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Attributes\Connection;
use Illuminate\Support\Facades\DB;
#[Connection('redis')]
class ProcessOrder implements ShouldQueue {}
DB::transaction(function () { ProcessOrder::dispatch()->onConnection('database'); });
PHP,
        'rules' => [],
        'absent' => ['TG001'],
        'config' => ['queue_default' => 'database', 'queue_after_commit' => ['redis' => true, 'database' => false]],
    ],
    'Laravel 13 dynamic Connection attribute is not trusted as safe' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Attributes\Connection;
use Illuminate\Support\Facades\DB;
#[Connection(QueueConnections::Primary)]
class ProcessOrder implements ShouldQueue {}
DB::transaction(function () { ProcessOrder::dispatch(); });
PHP,
        'rules' => ['TG001'],
        'config' => ['queue_default' => 'redis', 'queue_after_commit' => ['redis' => true]],
    ],
'''
if marker not in text:
    raise SystemExit("Laravel 13 route scenario marker not found")
text = text.replace(marker, scenarios + marker, 1)
matrix.write_text(text)

Path(".audit-request").unlink(missing_ok=True)
base = subprocess.run(["git", "show", "origin/main:.github/audit_writer.py"], check=True, capture_output=True, text=True).stdout
Path(".github/audit_writer.py").write_text(base)