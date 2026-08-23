from pathlib import Path

path = Path('src/Analysis/SourceScanner.php')
source = path.read_text()
old = "        $this->context = $this->classIndex->contextFor($this->file, $match['offset']);\n"
if source.count(old) != 1:
    raise SystemExit(f'expected stale captured() context write exactly once, found {source.count(old)}')
path.write_text(source.replace(old, '', 1))
