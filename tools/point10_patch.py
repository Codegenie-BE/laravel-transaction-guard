from __future__ import annotations

import subprocess
from pathlib import Path

index = Path('src/Analysis/ClassMetadataIndex.php')
text = index.read_text()
marker = "    /** @var array<string, string> Queue name to forwarded connection. */\n    private array $queueForwards = [];\n"
insert = marker + "\n    /** @var array<string, array<string, string>> */\n    private array $notificationChannelConnections = [];\n"
if marker not in text:
    raise SystemExit('index property marker missing')
text = text.replace(marker, insert, 1)

marker = "    public function queueRouteConnection(string $class): ?string\n    {\n"
method = r'''    /** @return array<string, string> */
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

'''
if marker not in text:
    raise SystemExit('queueRouteConnection marker missing')
text = text.replace(marker, method + marker, 1)

old = """            $fqcn = $context->namespace !== '' ? $context->namespace.'\\\\'.$name : $name;\n\n            $this->classes[strtolower($fqcn)] = new ClassMetadata(\n"""
new = """            $fqcn = $context->namespace !== '' ? $context->namespace.'\\\\'.$name : $name;\n            $notificationConnections = $this->notificationConnectionsForClass($tokens, $openBrace + 1, $closeBrace - 1, $source);\n            if ($notificationConnections !== null) {\n                $this->notificationChannelConnections[strtolower($fqcn)] = $notificationConnections;\n            }\n\n            $this->classes[strtolower($fqcn)] = new ClassMetadata(\n"""
if old not in text:
    raise SystemExit('class creation marker missing')
text = text.replace(old, new, 1)

marker = "    /**\n     * @param  list<Token>  $tokens\n     * @return array{bool,bool,string|null,string|null,bool|null,bool}\n     */\n    private function constructorQueueBehavior"
helper = r'''    /**
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

'''
if marker not in text:
    raise SystemExit('constructor marker missing')
text = text.replace(marker, helper + marker, 1)
index.write_text(text)

scanner = Path('src/Analysis/SourceScanner.php')
text = scanner.read_text()
old = """                if ($queued && ! $explicitlyBeforeCommit\n                    && ($this->statementContainsAfterCommit($statement) || $metadata->queueAfterCommit() === true || $this->queueConnectionDispatchesAfterCommit($statement, $metadata))) {\n                    continue;\n                }\n\n                $this->appendFinding($findings, $offset, 'TG004', Severity::Error,\n"""
new = """                if ($queued && ! $explicitlyBeforeCommit && $this->notificationDispatchIsAfterCommitSafe($statement, $metadata)) {\n                    continue;\n                }\n\n                $this->appendFinding($findings, $offset, 'TG004', Severity::Error,\n"""
if old not in text:
    raise SystemExit('notification safe block missing')
text = text.replace(old, new, 1)
old = """            if ($metadata?->queued() === true && $this->jobDispatchIsAfterCommitSafe($statement, $metadata)) {\n                continue;\n            }\n            $this->appendFinding($findings, $offset, 'TG004', Severity::Error,\n"""
new = """            if ($metadata?->queued() === true && $this->notificationDispatchIsAfterCommitSafe($statement, $metadata)) {\n                continue;\n            }\n            $this->appendFinding($findings, $offset, 'TG004', Severity::Error,\n"""
if old not in text:
    raise SystemExit('variable notification safe block missing')
text = text.replace(old, new, 1)
marker = "    private function queueConnectionDispatchesAfterCommit(string $statement, ?ClassMetadata $metadata = null): bool\n"
helper = r'''    private function notificationDispatchIsAfterCommitSafe(string $statement, ?ClassMetadata $metadata): bool
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

'''
if marker not in text:
    raise SystemExit('queue helper marker missing')
text = text.replace(marker, helper + marker, 1)
scanner.write_text(text)

matrix = Path('tests/Support/ScenarioMatrix.php')
text = matrix.read_text()
marker = "    'queued notification constructor afterCommit is safe' => [\n"
scenarios = r'''    'notification viaConnections unsafe override defeats safe default' => [
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
    'notification partial safe overrides do not rescue unsafe base' => [
        'code' => <<<'PHP'
<?php
namespace App\Notifications;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
class ReceiptReady implements ShouldQueue { public function viaConnections(): array { return ['mail' => 'redis']; } }
DB::transaction(function () { $user->notify(new ReceiptReady()); });
PHP,
        'rules' => ['TG004'],
        'config' => ['queue_default' => 'database', 'queue_after_commit' => ['redis' => true, 'database' => false]],
    ],
    'notification dynamic viaConnections value is not trusted as safe' => [
        'code' => <<<'PHP'
<?php
namespace App\Notifications;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
class ReceiptReady implements ShouldQueue { public function viaConnections(): array { return ['mail' => self::CONNECTION]; } }
DB::transaction(function () { $user->notify(new ReceiptReady()); });
PHP,
        'rules' => ['TG004'],
        'config' => ['queue_default' => 'redis', 'queue_after_commit' => ['redis' => true]],
    ],
'''
if marker not in text:
    raise SystemExit('notification scenario marker missing')
matrix.write_text(text.replace(marker, scenarios + marker, 1))

Path('.audit-request').unlink(missing_ok=True)
Path('tools/point10_patch.py').unlink(missing_ok=True)
base = subprocess.run(['git', 'show', 'origin/main:.github/audit_writer.py'], check=True, capture_output=True, text=True).stdout
Path('.github/audit_writer.py').write_text(base)
