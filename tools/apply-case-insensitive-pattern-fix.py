from pathlib import Path

path = Path('src/Analysis/SourceScanner.php')
source = path.read_text()
old = "        if (! str_contains($pattern, preg_quote($alias, '/'))) {"
new = "        if (stripos($pattern, preg_quote($alias, '/')) === false) {"
if source.count(old) != 1:
    raise SystemExit(f'expected pattern-binding check exactly once, found {source.count(old)}')
path.write_text(source.replace(old, new, 1))
