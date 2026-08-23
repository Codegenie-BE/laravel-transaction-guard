from pathlib import Path

script_path = Path('tools/apply-post-v040-hardening.py')
script = script_path.read_text()

start_marker = "source = replace_once(\n    source,\n    \"        foreach ($this->facadeAliases('Illuminate\\\\\\\\Support\\\\\\\\Facades\\\\\\\\Queue', 'Queue') as $alias) {\\n\""
start = script.find(start_marker)
end = script.find("\n\nredis_anchor =", start)
if start < 0 or end < 0:
    raise SystemExit('unable to locate optional Queue::connection patch block')

script = script[:start] + script[end + 2:]
exec(compile(script, str(script_path), 'exec'), {'__name__': '__main__'})
