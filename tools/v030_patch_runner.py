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

# re.sub replacement strings collapse one level of backslash escaping. Normalize
# the generated PHP literal so a single backslash value is represented as '\\'.
finding_path = Path(__file__).resolve().parents[1] / 'src/Analysis/Finding.php'
finding = finding_path.read_text()
finding = finding.replace(
    "str_replace('\\', '/', realpath($root)",
    "str_replace('\\\\', '/', realpath($root)",
    1,
)
finding_path.write_text(finding)
