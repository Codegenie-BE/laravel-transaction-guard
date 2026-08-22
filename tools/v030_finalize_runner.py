from pathlib import Path

path = Path(__file__).with_name('v030_finalize.py')
code = path.read_text()
start = code.index('# Queue route/forward declarations can live in any namespace section.')
end = code.index('# Add map-wide facade aliases before the existing per-context helper.')
replacement = r'''# Queue route/forward declarations can live in any namespace section.
text = read('src/Analysis/ClassMetadataIndex.php')
text = text.replace(
    'private function indexQueueRoutes(string $source, array $tokens, FileContext $context): void',
    'private function indexQueueRoutes(string $source, array $tokens, FileContextMap $contexts): void',
    1,
)
route_start = text.index('private function indexQueueRoutes')
route_end = text.index('private function parseQueueRouteArguments', route_start)
route = text[route_start:route_end]
route = route.replace('$this->facadeAliases($context,', '$this->facadeAliasesForMap($contexts,', 1)
needle = "            foreach ($matches[0] as [$matched, $offset]) {\n                if ($this->offsetIsNonCode($tokens, $offset)) {\n                    continue;\n                }"
replacement_loop = "            foreach ($matches[0] as [$matched, $offset]) {\n                $context = $contexts->at($offset);\n                if (! in_array($alias, $this->facadeAliases($context, 'Illuminate\\\\Support\\\\Facades\\\\Queue', 'Queue'), true)\n                    || $this->offsetIsNonCode($tokens, $offset)) {\n                    continue;\n                }"
if needle not in route:
    raise RuntimeError('queue route match loop not found')
route = route.replace(needle, replacement_loop, 1)
text = text[:route_start] + route + text[route_end:]

text = text.replace(
    'private function indexQueueForwards(string $source, array $tokens, FileContext $context): void',
    'private function indexQueueForwards(string $source, array $tokens, FileContextMap $contexts): void',
    1,
)
forward_start = text.index('private function indexQueueForwards')
forward_end = text.index('private function parseQueueForwardArguments', forward_start)
forward = text[forward_start:forward_end]
forward = forward.replace('$this->facadeAliases($context,', '$this->facadeAliasesForMap($contexts,', 1)
if needle not in forward:
    raise RuntimeError('queue forward match loop not found')
forward = forward.replace(needle, replacement_loop, 1)
text = text[:forward_start] + forward + text[forward_end:]
write('src/Analysis/ClassMetadataIndex.php', text)

'''
code = code[:start] + replacement + code[end:]
exec(compile(code, str(path), 'exec'), {'__file__': str(path), '__name__': '__main__'})
