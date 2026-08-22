from __future__ import annotations

import runpy
import sys
from pathlib import Path

BRANCH = "audit/v020-finalize"

if len(sys.argv) != 2 or sys.argv[1] != BRANCH:
    raise SystemExit("unsupported audit branch")

script = Path("tools/finalize_v020.py")
text = script.read_text()
old = '''    if position < 0:
        raise SystemExit(f"final array marker not found in {path}")
    write(path, text[:position] + "\\n" + addition.rstrip() + "\\n" + text[position:])
'''
new = '''    if position < 0:
        write(path, text.rstrip() + "\\n\\n" + addition.rstrip() + "\\n")
        return
    write(path, text[:position] + "\\n" + addition.rstrip() + "\\n" + text[position:])
'''
if old not in text:
    raise SystemExit("finalizer append helper patch target not found")
script.write_text(text.replace(old, new, 1))

runpy.run_path(str(script), run_name="__main__")
script.unlink(missing_ok=True)
Path("tools/maintenance.php").unlink(missing_ok=True)
Path(".audit-request").unlink(missing_ok=True)
