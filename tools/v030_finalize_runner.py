from pathlib import Path

path = Path(__file__).with_name('v030_finalize.py')
code = path.read_text()

# Replace the Queue route/forward section with position-based edits so Python
# and PHP backslash escaping cannot invalidate the patch anchors.
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

# Insert namespace-relative resolution immediately before FileContext resolves
# imported aliases, instead of matching a PHP backslash literal in source text.
start = code.index('# FileContext understands namespace-relative names.')
end = code.index('# SourceScanner chooses context by match offset and sees aliases from all sections.')
replacement = r'''# FileContext understands namespace-relative names.
context_source = read('src/Analysis/FileContext.php')
marker = "        [$first] = explode('\\\\', $name, 2);"
pos = context_source.find(marker)
if pos < 0:
    marker = '        [$first] = explode('
    pos = context_source.find(marker)
if pos < 0:
    raise RuntimeError('FileContext alias-resolution marker not found')
insertion = r'''        if (str_starts_with(strtolower($name), 'namespace\\')) {
            $relative = substr($name, strlen('namespace\\'));

            return $this->namespace !== '' ? $this->namespace.'\\'.$relative : $relative;
        }

'''
context_source = context_source[:pos] + insertion + context_source[pos:]
write('src/Analysis/FileContext.php', context_source)

'''
code = code[:start] + replacement + code[end:]

exec(compile(code, str(path), 'exec'), {'__file__': str(path), '__name__': '__main__'})

# This tracked runner is intentionally removed after the validated finalize run.
