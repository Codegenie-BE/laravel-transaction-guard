from pathlib import Path

patch = Path(__file__).with_name('v040_patch.py')
code = patch.read_text()
old = "updated, count = re.subn(pattern, replacement, text, count=1, flags=re.S)"
new = "updated, count = re.subn(pattern, lambda _match: replacement, text, count=1, flags=re.S)"
if old not in code:
    raise RuntimeError('v0.4 regex_once anchor not found')
code = code.replace(old, new, 1)
namespace = {'__file__': str(patch), '__name__': '__main__'}
exec(compile(code, str(patch), 'exec'), namespace)
Path(__file__).unlink()
