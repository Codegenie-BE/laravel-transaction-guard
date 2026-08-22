from pathlib import Path
import re
import runpy

ROOT = Path(__file__).resolve().parents[1]
PATCH = ROOT / 'tools/v040_patch.py'
SCANNER = ROOT / 'src/Analysis/SourceScanner.php'

code = PATCH.read_text()
risky = r'''regex_once('src/Analysis/SourceScanner.php',
r'''        foreach \(\$this->matches\('/\(\?P<var>.*?save\|saveQuietly.*?decrement\)\\s\*\\\(/i'\) as \$match\) \{''',
'''        $instanceMethods = OperationCatalog::alternation(OperationCatalog::ELOQUENT_INSTANCE_MUTATIONS);
        foreach ($this->matches('/(?P<var>\\$[A-Za-z_][A-Za-z0-9_]*)\\s*->\\s*(?P<method>'.$instanceMethods.')\\s*\\(/i') as $match) {''')'''
# Building the exact string above with nested triple quotes is deliberately
# avoided below; locate the single risky call by its unique marker boundaries.
start_marker = "regex_once('src/Analysis/SourceScanner.php',\nr'''        foreach \\(\\$this->matches\\('/\\(\\?P<var>.*?save\\|saveQuietly"
start = code.find(start_marker)
if start < 0:
    raise RuntimeError('risky Eloquent rewrite start not found')
end_marker = "foreach ($this->matches('/(?P<var>\\\\$[A-Za-z_][A-Za-z0-9_]*)\\\\s*->\\\\s*(?P<method>'.$instanceMethods.')\\\\s*\\\\(/i') as $match) {''')"
end = code.find(end_marker, start)
if end < 0:
    raise RuntimeError('risky Eloquent rewrite end not found')
end += len(end_marker)
code = code[:start] + "# Eloquent instance matcher is normalized structurally by v040_safe_runner.py" + code[end:]
PATCH.write_text(code)

# Run the resilient final patch pipeline. It also removes the older runner
# helpers from the working tree on success.
runpy.run_path(str(ROOT / 'tools/v040_resilient_runner.py'), run_name='__main__')

source = SCANNER.read_text()
eloquent_pos = source.find('    private function scanEloquentCrossConnectionWrites')
if eloquent_pos < 0:
    raise RuntimeError('scanEloquentCrossConnectionWrites not found after patch')
eloquent_end = source.find('    private function localModelConnectionForVariable', eloquent_pos)
if eloquent_end < 0:
    eloquent_end = source.find('    private function eloquentConnectionFromStatement', eloquent_pos)
if eloquent_end < 0:
    raise RuntimeError('Eloquent scanner boundary not found')
segment = source[eloquent_pos:eloquent_end]
pattern = re.compile(
    r"(?P<indent>        )foreach \(\$this->matches\('/\(\?P<var>\\\$\[A-Za-z_\]\[A-Za-z0-9_\]\*\)\\s\*->\\s\*\(\?P<method>save\|saveQuietly\|update\|updateQuietly\|delete\|deleteQuietly\|forceDelete\|forceDeleteQuietly\|restore\|restoreQuietly\|touch\|touchQuietly\|push\|pushQuietly\|increment\|decrement\)\\s\*\\\(/i'\) as \$match\) \{"
)
match = pattern.search(segment)
if match is None:
    # Fall back to a bounded matcher within this method only; unlike the old
    # repository-wide regex this cannot consume neighboring scanner methods.
    pattern = re.compile(r"        foreach \(\$this->matches\('/\(\?P<var>.*?\(\?P<method>save\|saveQuietly.*?decrement\)\\s\*\\\(/i'\) as \$match\) \{", re.S)
    match = pattern.search(segment)
if match is None:
    raise RuntimeError('Eloquent instance matcher not found inside bounded method')
replacement = "        $instanceMethods = OperationCatalog::alternation(OperationCatalog::ELOQUENT_INSTANCE_MUTATIONS);\n        foreach ($this->matches('/(?P<var>\\$[A-Za-z_][A-Za-z0-9_]*)\\s*->\\s*(?P<method>'.$instanceMethods.')\\s*\\(/i') as $match) {"
segment = segment[:match.start()] + replacement + segment[match.end():]
source = source[:eloquent_pos] + segment + source[eloquent_end:]
SCANNER.write_text(source)

if Path(__file__).exists():
    Path(__file__).unlink()
