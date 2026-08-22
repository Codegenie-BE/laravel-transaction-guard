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

scanner = Path("src/Analysis/SourceScanner.php")
text = scanner.read_text()
marker = '''        }
    }

    /** @return array{kind:string,connection:string|null}|null */
    private function localFacadeHandleForVariable'''
addition = r'''        }

        $builderMutations = 'insert|insertGetId|insertOrIgnore|insertUsing|update|updateOrInsert|upsert|delete|truncate|increment|decrement|statement|unprepared|affectingStatement';
        foreach ($this->matches('/(?P<var>\$[A-Za-z_][A-Za-z0-9_]*)\s*->\s*(?:table|query)\s*\((?:(?!;).)*?\)(?:(?!;).)*?\b(?P<method>'.$builderMutations.')\s*\(/is') as $match) {
            $offset = $match['offset'];
            if ($this->eligibleTransaction($offset) === null) {
                continue;
            }

            $handle = $this->localFacadeHandleForVariable($offset, $this->captured($match, 'var'));
            if ($handle === null || $handle['kind'] !== 'db') {
                continue;
            }

            $this->reportCrossConnectionWrite(
                $findings,
                $offset,
                $handle['connection'] ?? $this->config->defaultDatabaseConnection,
            );
        }
    }

    /** @return array{kind:string,connection:string|null}|null */
    private function localFacadeHandleForVariable'''
if marker not in text:
    raise SystemExit("local DB builder insertion marker not found")
scanner.write_text(text.replace(marker, addition, 1))

script.unlink(missing_ok=True)
Path("tools/maintenance.php").unlink(missing_ok=True)
Path(".audit-request").unlink(missing_ok=True)
