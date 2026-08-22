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

Path(__file__).unlink()
