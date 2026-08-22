from __future__ import annotations

import subprocess
from pathlib import Path

source = Path('src/Analysis/SourceScanner.php')
text = source.read_text()
old = """                $metadata = $this->classIndex->metadata($resolved);\n                $method = $this->captured($match, 'method');\n                $statement = $this->statementAt($offset);\n                $looksLikeJob = $metadata?->queued() === true\n                    || str_contains(strtolower($resolved), '\\\\jobs\\\\')\n                    || preg_match('/\\\\\\\\Jobs\\\\\\\\/', $resolved) === 1;\n"""
new = """                $metadata = $this->classIndex->metadata($resolved);\n                $method = $this->captured($match, 'method');\n                $statement = $this->statementAt($offset);\n                $globalDispatchHelper = $method === '';\n                $looksLikeJob = ($globalDispatchHelper && $metadata === null)\n                    || $metadata?->queued() === true\n                    || str_contains(strtolower($resolved), '\\\\jobs\\\\')\n                    || preg_match('/\\\\\\\\Jobs\\\\\\\\/', $resolved) === 1;\n"""
if old not in text:
    raise SystemExit('dispatch block not found')
source.write_text(text.replace(old, new, 1))

matrix = Path('tests/Support/ScenarioMatrix.php')
text = matrix.read_text()
marker = "    'fully qualified DB and Http facades are detected' => [\n"
scenario = r'''    'global dispatch helper with unresolved metadata is conservatively reported' => [
        'code' => <<<'PHP'
<?php
namespace App\Actions;
use Illuminate\Support\Facades\DB;
DB::transaction(function () { dispatch(new \Vendor\Package\RecalculateOrder()); });
PHP,
        'rules' => ['TG001'],
    ],
    'global dispatch helper with unresolved metadata is safe after commit' => [
        'code' => <<<'PHP'
<?php
namespace App\Actions;
use Illuminate\Support\Facades\DB;
DB::transaction(function () { dispatch(new \Vendor\Package\RecalculateOrder())->afterCommit(); });
PHP,
        'rules' => [],
        'absent' => ['TG001'],
    ],
'''
if marker not in text:
    raise SystemExit('scenario marker not found')
if 'global dispatch helper with unresolved metadata is conservatively reported' not in text:
    matrix.write_text(text.replace(marker, scenario + marker, 1))

Path('.audit-request').unlink(missing_ok=True)
restored = subprocess.run(
    ['git', 'show', 'origin/main:.github/audit_writer.py'],
    check=True,
    capture_output=True,
    text=True,
).stdout
Path('.github/audit_writer.py').write_text(restored)
