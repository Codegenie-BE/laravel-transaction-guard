from pathlib import Path
import re
import runpy

ROOT = Path(__file__).resolve().parents[1]
PATCH = ROOT / 'tools/v040_patch.py'
SCANNER = ROOT / 'src/Analysis/SourceScanner.php'

code = PATCH.read_text()

# Disable the one repository-wide Eloquent regex that can begin at the generic
# variable-handle scanner and consume unrelated methods before reaching the
# later Eloquent save matcher. The replacement is re-applied below, bounded to
# scanEloquentCrossConnectionWrites() only.
start_marker = "regex_once('src/Analysis/SourceScanner.php',\nr'''        foreach \\(\\$this->matches\\('/\\(\\?P<var>.*?save\\|saveQuietly"
start = code.find(start_marker)
if start < 0:
    raise RuntimeError('risky Eloquent rewrite start not found')
next_marker = "replace_once('src/Analysis/SourceScanner.php',\n'''            $connection = $this->classIndex->modelConnection"
end = code.find(next_marker, start)
if end < 0:
    raise RuntimeError('risky Eloquent rewrite boundary not found')
code = code[:start] + "# Eloquent instance matcher is normalized structurally by v040_safe_runner.py\n" + code[end:]
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

# This regex is intentionally allowed to be broad only inside the already
# bounded Eloquent method segment, so it cannot consume neighboring scanners.
pattern = re.compile(
    r"        foreach \(\$this->matches\('/\(\?P<var>.*?save\|saveQuietly.*?decrement\)\\s\*\\\(/i'\) as \$match\) \{",
    re.S,
)
match = pattern.search(segment)
if match is None:
    raise RuntimeError('Eloquent instance matcher not found inside bounded method')
replacement = "        $instanceMethods = OperationCatalog::alternation(OperationCatalog::ELOQUENT_INSTANCE_MUTATIONS);\n        foreach ($this->matches('/(?P<var>\\$[A-Za-z_][A-Za-z0-9_]*)\\s*->\\s*(?P<method>'.$instanceMethods.')\\s*\\(/i') as $match) {"
segment = segment[:match.start()] + replacement + segment[match.end():]
source = source[:eloquent_pos] + segment + source[eloquent_end:]
SCANNER.write_text(source)

Path(__file__).unlink()
