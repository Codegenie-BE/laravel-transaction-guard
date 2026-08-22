from pathlib import Path
import runpy

root = Path(__file__).resolve().parents[1]
patch = root / 'tools/v040_patch.py'
code = patch.read_text()
old = '''# Scenario matrix aggregates v0.4 module without re-expanding the legacy fixture.
text = read('tests/Support/ScenarioMatrix.php')
if "Scenarios/V040Hardening.php" not in text:
    if not text.rstrip().endswith('return $scenarios;'):
        raise RuntimeError('ScenarioMatrix return anchor not found')
    text = text.replace('return $scenarios;', "$scenarios = array_merge($scenarios, require __DIR__.'/Scenarios/V040Hardening.php');\\n\\nreturn $scenarios;")
    write('tests/Support/ScenarioMatrix.php', text)
'''
new = '''# Scenario matrix aggregates v0.4 beside the existing v0.3 module.
text = read('tests/Support/ScenarioMatrix.php')
if "Scenarios/V040Hardening.php" not in text:
    v030_footer = "return array_merge($scenarios, require __DIR__.'/Scenarios/V030Hardening.php');"
    if v030_footer in text:
        text = text.replace(v030_footer, "return array_merge(\\n    $scenarios,\\n    require __DIR__.'/Scenarios/V030Hardening.php',\\n    require __DIR__.'/Scenarios/V040Hardening.php',\\n);")
    elif text.rstrip().endswith('return $scenarios;'):
        text = text.replace('return $scenarios;', "$scenarios = array_merge($scenarios, require __DIR__.'/Scenarios/V040Hardening.php');\\n\\nreturn $scenarios;")
    else:
        raise RuntimeError('ScenarioMatrix return anchor not found')
    write('tests/Support/ScenarioMatrix.php', text)
'''
if old not in code:
    raise RuntimeError('v0.4 scenario aggregation patch anchor not found')
patch.write_text(code.replace(old, new, 1))

runpy.run_path(str(root / 'tools/v040_runner.py'), run_name='__main__')
Path(__file__).unlink()
