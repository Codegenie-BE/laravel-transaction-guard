from __future__ import annotations

import subprocess
import sys
from pathlib import Path


BRANCH = "audit/05-branch-aware-manual-transactions"

if len(sys.argv) != 2 or sys.argv[1] != BRANCH:
    raise SystemExit("unsupported audit branch")

Path("tools/maintenance.php").unlink(missing_ok=True)
Path(".audit-request").unlink(missing_ok=True)

base = subprocess.run(
    ["git", "show", "origin/main:.github/audit_writer.py"],
    check=True,
    capture_output=True,
    text=True,
).stdout
Path(".github/audit_writer.py").write_text(base)
