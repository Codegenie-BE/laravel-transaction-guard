from pathlib import Path

path = Path('src/Analysis/SourceScanner.php')
source = path.read_text()

replacements = [
    ("\n    private FileContext $context;\n", "\n", 'unused scanner context property'),
    ("        $this->context = $this->classIndex->contextFor($file);\n", "", 'unused scanner context assignment'),
    ("$kind = OperationCatalog::redisMethodKind((string) ($call['method'] ?? ''));", "$kind = OperationCatalog::redisMethodKind((string) $call['method']);", 'non-null regex method capture'),
]

for old, new, label in replacements:
    count = source.count(old)
    if count != 1:
        raise SystemExit(f'{label}: expected exactly one match, found {count}')
    source = source.replace(old, new, 1)

path.write_text(source)
