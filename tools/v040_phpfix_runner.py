from pathlib import Path
import runpy

ROOT = Path(__file__).resolve().parents[1]
runpy.run_path(str(ROOT / 'tools/v040_safe_runner.py'), run_name='__main__')

for relative in ['src/Analysis/ClassMetadata.php', 'src/Analysis/ClassMetadataIndex.php']:
    path = ROOT / relative
    source = path.read_text()
    # Non-raw Python replacement strings in the deterministic patch can reduce
    # a valid PHP '\\' character-set literal to the invalid '\'. Normalize all
    # newly introduced ltrim() variables that use a backslash character set.
    for variable in ['$trait', '$class']:
        source = source.replace(
            f"ltrim({variable}, '\\')",
            f"ltrim({variable}, '\\\\')",
        )
    path.write_text(source)

scanner_path = ROOT / 'src/Analysis/SourceScanner.php'
scanner = scanner_path.read_text()
scanner = scanner.replace(
    '"Dispatch of non-queueable [{$this->basename($class)}] executes synchronously while the database transaction is open."',
    '"Dispatch of non-queueable [{$this->basename($metadata->name)}] executes synchronously while the database transaction is open."',
)
scanner = scanner.replace(
    '"Dispatch of non-queueable [{$this->basename($class)}] executes synchronously inside the transaction."',
    '"Dispatch of non-queueable [{$this->basename($metadata->name)}] executes synchronously inside the transaction."',
)
scanner = scanner.replace(
    '@return array{Severity,string|null}',
    '@return array{Severity,string|null,string}',
    1,
)
scanner = scanner.replace(
    '/** @param list<Finding> $findings @param TransactionRegion $tx */\n    private function appendPendingDispatchLifecycleFindings',
    '/**\n     * @param list<Finding> $findings\n     * @param TransactionRegion $tx\n     */\n    private function appendPendingDispatchLifecycleFindings',
    1,
)
scanner_path.write_text(scanner)

guard_path = ROOT / 'src/TransactionGuard.php'
guard = guard_path.read_text()
needle = '''     * @param  list<string>  $excludePatterns
     * @return list<string>
     */
    public function discoverPhpFiles'''
replacement = '''     * @param  list<string>  $excludePatterns
     * @return list<string>
     * @phpstan-impure
     */
    public function discoverPhpFiles'''
if needle not in guard:
    raise RuntimeError('discoverPhpFiles PHPStan annotation anchor missing')
guard_path.write_text(guard.replace(needle, replacement, 1))

# RuleCatalog is now the semantic source of truth. Keep README's variable
# driver-aware TG012 severity aligned with the canonical definition.
readme_path = ROOT / 'README.md'
readme = readme_path.read_text()
readme = readme.replace('| `TG012` | critical |', '| `TG012` | critical / warning |', 1)
readme_path.write_text(readme)

Path(__file__).unlink()
