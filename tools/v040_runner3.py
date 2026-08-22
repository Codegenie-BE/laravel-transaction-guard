from pathlib import Path
import runpy

root = Path(__file__).resolve().parents[1]
runner = root / 'tools/v040_runner.py'
code = runner.read_text()

old_cache = """source, count = cache_pattern.subn(lambda _match: cache_replacement, source, count=1)\nif count != 1:\n    raise RuntimeError(f'cache scanner fallback matched {count}')\n"""
new_cache = """if 'private function scanRateLimiter' not in source:\n    source, count = cache_pattern.subn(lambda _match: cache_replacement, source, count=1)\n    if count != 1:\n        raise RuntimeError(f'cache scanner fallback matched {count}')\n"""
if old_cache not in code:
    raise RuntimeError('cache fallback anchor not found')
code = code.replace(old_cache, new_cache, 1)

old_redis = """source, count = redis_pattern.subn(lambda _match: redis_replacement, source, count=1)\nif count != 1:\n    raise RuntimeError(f'Redis scanner fallback matched {count}')\n"""
new_redis = """if 'OperationCatalog::redisCommandKind' not in source:\n    source, count = redis_pattern.subn(lambda _match: redis_replacement, source, count=1)\n    if count != 1:\n        raise RuntimeError(f'Redis scanner fallback matched {count}')\n"""
if old_redis not in code:
    raise RuntimeError('Redis fallback anchor not found')
code = code.replace(old_redis, new_redis, 1)
runner.write_text(code)

runpy.run_path(str(root / 'tools/v040_runner2.py'), run_name='__main__')
Path(__file__).unlink()
