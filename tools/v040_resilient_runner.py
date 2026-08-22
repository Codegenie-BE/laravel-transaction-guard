from pathlib import Path
import runpy

root = Path(__file__).resolve().parents[1]
runner = root / 'tools/v040_final_runner.py'
code = runner.read_text()
old = """if 'private function scanRateLimiter' not in source:\n    start, end = method_region(source, 'scanCache', 'scanRedis')\n    cache_code = r'''"""
new = """if 'private function scanRateLimiter' not in source:\n    try:\n        start, end = method_region(source, 'scanCache', 'scanRedis')\n    except RuntimeError:\n        redis_pos = source.find('    private function scanRedis')\n        if redis_pos < 0:\n            raise RuntimeError('scanRedis method not found while inserting cache scanner')\n        start = source.rfind('    /**', 0, redis_pos)\n        if start < 0:\n            start = redis_pos\n        end = start\n    cache_code = r'''"""
if old not in code:
    raise RuntimeError('cache fallback insertion anchor not found')
runner.write_text(code.replace(old, new, 1))
runpy.run_path(str(runner), run_name='__main__')
Path(__file__).unlink()
