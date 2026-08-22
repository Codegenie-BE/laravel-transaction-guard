from pathlib import Path
import runpy

ROOT = Path(__file__).resolve().parents[1]
runpy.run_path(str(ROOT / 'tools/v040_safe_runner.py'), run_name='__main__')

for relative in ['src/Analysis/ClassMetadata.php', 'src/Analysis/ClassMetadataIndex.php']:
    path = ROOT / relative
    source = path.read_text()
    # Python string-literal interpolation in the deterministic patch can reduce
    # a PHP single-quoted two-backslash literal to one backslash. Normalize the
    # newly introduced trait ltrim() calls back to valid PHP source.
    source = source.replace("ltrim($trait, '\\')", "ltrim($trait, '\\\\')")
    path.write_text(source)

Path(__file__).unlink()
