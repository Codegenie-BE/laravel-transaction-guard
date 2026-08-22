from __future__ import annotations

import subprocess
import sys
from pathlib import Path


BRANCH = "audit/05-branch-aware-manual-transactions"

if len(sys.argv) != 2 or sys.argv[1] != BRANCH:
    raise SystemExit("unsupported audit branch")

source = Path("src/Analysis/SourceScanner.php")
text = source.read_text()
text = text.replace(
    """            $start = end($stacks[$key]);\n            if ($start === false || ! $this->manualTerminalCloses($start, $call)) {\n""",
    """            $start = end($stacks[$key]);\n            if (! $this->manualTerminalCloses($start, $call)) {\n""",
    1,
)
text = text.replace(
    """                if ($start === null) {\n                    return;\n                }\n\n                $end = $endOffset ?? strlen($this->source);\n""",
    """                if ($start === null) {\n                    return;\n                }\n\n                /** @var DatabaseControlCall $start */\n                $end = $endOffset ?? strlen($this->source);\n""",
    1,
)
source.write_text(text)

Path("tools/maintenance.php").unlink(missing_ok=True)
Path(".audit-request").unlink(missing_ok=True)

base = subprocess.run(
    ["git", "show", "origin/main:.github/audit_writer.py"],
    check=True,
    capture_output=True,
    text=True,
).stdout
Path(".github/audit_writer.py").write_text(base)
