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

# Bounded static reduction must tolerate a source tail that is not independently
# parseable (for example the remainder of a transaction closure). token_get_all
# is sufficient because this resolver only consumes literal tokens.
resolver_path = ROOT / 'src/Analysis/StaticExpressionResolver.php'
resolver = resolver_path.read_text()
resolver = resolver.replace(
    """        try {
            $tokens = token_get_all('<?php '.$call, TOKEN_PARSE);
        } catch (\\ParseError) {
            return null;
        }
""",
    """        $tokens = token_get_all('<?php '.$call);
""",
)
resolver_path.write_text(resolver)

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

# Metadata is the preferred static event signal, but retain the established
# App\\Events-style namespace fallback so v0.4 does not regress valid v0.3
# coverage for events that use a different dispatch trait arrangement.
scanner = scanner.replace(
    "            $looksLikeEvent = $this->classIndex->isDispatchableEvent($class);\n",
    "            $looksLikeEvent = $this->classIndex->isDispatchableEvent($class)\n                || str_contains(strtolower($class), '\\\\events\\\\');\n",
    1,
)

# Explicitly cover Laravel's multi-column counter mutations. This small
# dedicated pattern intentionally has no named method capture, avoiding any
# method-chain filtering while the central catalog remains the shared source of
# mutation names for the general path.
needle = """        $mutations = OperationCatalog::alternation(OperationCatalog::QUERY_MUTATIONS);

        foreach ($this->facadeAliases('Illuminate\\Support\\Facades\\DB', 'DB') as $alias) {
"""
replacement = """        $mutations = OperationCatalog::alternation(OperationCatalog::QUERY_MUTATIONS);

        foreach ($this->facadeAliases('Illuminate\\Support\\Facades\\DB', 'DB') as $alias) {
            $counterPattern = '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\\s*::\\s*connection\\s*\\(\\s*(?P<quote>[\\\'\\\"])(?P<connection>[^\\\'\\\"]+)\\k<quote>\\s*\\)\\s*->(?:(?![;{}]).)*?\\b(?:incrementEach|decrementEach)\\s*\\(/is';
            foreach ($this->matches($counterPattern) as $match) {
                $this->reportCrossConnectionWrite($findings, $match['offset'], $this->captured($match, 'connection'));
            }
"""
if needle not in scanner:
    raise RuntimeError('cross-connection counter insertion anchor missing')
scanner = scanner.replace(needle, replacement, 1)
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

# Laravel 12 does not expose every Laravel 13 PendingDispatch lifecycle hook.
# The contract test should assert ordering only when the framework surface is
# actually present, while still pinning dispatch/unique ordering where possible.
contract_path = ROOT / 'tests/Feature/FrameworkContractTest.php'
contract = contract_path.read_text()
old_contract = """    $prepare = strpos($source, 'prepareForDispatch()');
    $unique = strpos($source, 'UniqueLock');
    $dispatch = strpos($source, '->dispatch($this->job)');

    expect($prepare)->not->toBeFalse()
        ->and($unique)->not->toBeFalse()
        ->and($dispatch)->not->toBeFalse()
        ->and($prepare)->toBeLessThan($dispatch)
        ->and($unique)->toBeLessThan($dispatch);
"""
new_contract = """    $prepare = strpos($source, 'prepareForDispatch()');
    $unique = strpos($source, 'UniqueLock');
    $dispatch = strpos($source, '->dispatch($this->job)');

    expect($dispatch)->not->toBeFalse();

    if (interface_exists(\\Illuminate\\Contracts\\Queue\\PreparesForDispatch::class)) {
        expect($prepare)->not->toBeFalse()
            ->and($prepare)->toBeLessThan($dispatch);
    }

    if ($unique !== false) {
        expect($unique)->toBeLessThan($dispatch);
    }
"""
if old_contract in contract:
    contract = contract.replace(old_contract, new_contract, 1)
contract_path.write_text(contract)

Path(__file__).unlink()
