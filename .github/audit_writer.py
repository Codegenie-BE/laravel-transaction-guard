from __future__ import annotations

import runpy
import sys

BRANCH = "audit/09-local-variable-payload-types"
if len(sys.argv) != 2 or sys.argv[1] != BRANCH:
    raise SystemExit("unsupported audit branch")

runpy.run_path("tools/point9_patch.py", run_name="__main__")
