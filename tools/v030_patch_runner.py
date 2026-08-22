from pathlib import Path

path = Path(__file__).with_name('v030_patch.py')
code = path.read_text()
start = code.index("# localNewClassForVariable section (second assignment loop)")
end = code.index("# localFacadeHandleForVariable section.")
replacement = r'''# local closure variable inference is patched specifically; localNewClass was
# already covered by the first generic assignment replacement above.
replace('src/Analysis/SourceScanner.php',
"""        $scope = $this->callableScopeAt($offset);
        $resolved = null;
        $count = count($this->tokens);

        for ($i = 0; $i < $count; $i++) {
""",
"""        $scope = $this->callableScopeAt($offset);
        $resolved = null;
        $assignments = 0;
        $count = count($this->tokens);

        for ($i = 0; $i < $count; $i++) {
""", count=1)
replace('src/Analysis/SourceScanner.php',
"""            if ($this->callableScopeAt($token['offset']) !== $scope) {
                continue;
            }

            $assign = $this->nextSignificantToken($i + 1);
            if ($assign === null || $this->tokens[$assign]['text'] !== '=') {
                continue;
            }

            $value = $this->nextSignificantToken($assign + 1);
""",
"""            if ($this->callableScopeAt($token['offset']) !== $scope) {
                continue;
            }

            $assign = $this->nextSignificantToken($i + 1);
            if ($assign === null || $this->tokens[$assign]['text'] !== '=') {
                continue;
            }
            $assignments++;
            if ($assignments > 1 || $this->conditionalControlScopeAt($token['offset']) !== null) {
                return null;
            }

            $value = $this->nextSignificantToken($assign + 1);
""", count=1)
'''
code = code[:start] + replacement + code[end:]
exec(compile(code, str(path), 'exec'), {'__file__': str(path), '__name__': '__main__'})

root = Path(__file__).resolve().parents[1]

# re.sub replacement strings collapse one level of backslash escaping. Normalize
# the generated PHP literal so a single backslash value is represented as '\\'.
finding_path = root / 'src/Analysis/Finding.php'
finding = finding_path.read_text()
finding = finding.replace(
    "str_replace('\\', '/', realpath($root)",
    "str_replace('\\\\', '/', realpath($root)",
    1,
)
finding_path.write_text(finding)

# Write readonly configuration state using local accumulation and one assignment.
config_path = root / 'src/Analysis/AnalysisConfig.php'
config = config_path.read_text()
config = config.replace(
    '    private array $compiledCustomSideEffectPatterns = [];',
    '    private array $compiledCustomSideEffectPatterns;',
    1,
)
config = config.replace(
    '        $this->disabledRuleLookup = $normalizedDisabled;\n\n        foreach ($this->customSideEffectPatterns as $pattern) {',
    '        $this->disabledRuleLookup = $normalizedDisabled;\n        $compiledCustomSideEffectPatterns = [];\n\n        foreach ($this->customSideEffectPatterns as $pattern) {',
    1,
)
config = config.replace(
    '            $this->compiledCustomSideEffectPatterns[] = $regex;',
    '            $compiledCustomSideEffectPatterns[] = $regex;',
    1,
)
config = config.replace(
    '        }\n    }\n\n    /** @return list<string> */\n    public function customRegexes(): array',
    '        }\n\n        $this->compiledCustomSideEffectPatterns = $compiledCustomSideEffectPatterns;\n    }\n\n    /** @return list<string> */\n    public function customRegexes(): array',
    1,
)
config_path.write_text(config)

# PHPStan knows Composer class-loader callable arrays are non-empty.
metadata_path = root / 'src/Analysis/ClassMetadataIndex.php'
metadata = metadata_path.read_text()
metadata = metadata.replace(
    "$loader = is_array($autoload) ? ($autoload[0] ?? null) : null;",
    "$loader = is_array($autoload) ? $autoload[0] : null;",
    1,
)
metadata_path.write_text(metadata)

# TransactionGuard lives one namespace above Analysis; import the canonical catalog.
guard_path = root / 'src/TransactionGuard.php'
guard = guard_path.read_text()
needle = 'use Codegenie\\TransactionGuard\\Analysis\\Finding;\n'
if 'use Codegenie\\TransactionGuard\\Analysis\\RuleCatalog;' not in guard:
    guard = guard.replace(needle, needle + 'use Codegenie\\TransactionGuard\\Analysis\\RuleCatalog;\n', 1)
guard_path.write_text(guard)
