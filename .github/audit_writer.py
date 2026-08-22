from __future__ import annotations

import runpy
import sys
from pathlib import Path

BRANCH = "audit/v020-finalize"

if len(sys.argv) != 2 or sys.argv[1] != BRANCH:
    raise SystemExit("unsupported audit branch")

runpy.run_path("tools/finalize_v020.py", run_name="__main__")
Path("tools/finalize_v020.py").unlink(missing_ok=True)
Path("tools/maintenance.php").unlink(missing_ok=True)
Path(".audit-request").unlink(missing_ok=True)
