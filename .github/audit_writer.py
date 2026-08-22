from __future__ import annotations

import runpy
import sys

BRANCH = "audit/10-notification-via-connections"
if len(sys.argv) != 2 or sys.argv[1] != BRANCH:
    raise SystemExit("unsupported audit branch")

runpy.run_path("tools/point10_patch.py", run_name="__main__")
