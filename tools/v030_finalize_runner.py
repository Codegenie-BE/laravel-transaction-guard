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
# imported aliases, without matching a PHP backslash literal.
start = code.index('# FileContext understands namespace-relative names.')
end = code.index('# SourceScanner chooses context by match offset and sees aliases from all sections.')
replacement = r'''# FileContext understands namespace-relative names.
context_source = read('src/Analysis/FileContext.php')
marker = '        [$first] = explode('
pos = context_source.find(marker)
if pos < 0:
    raise RuntimeError('FileContext alias-resolution marker not found')
insertion = (
    "        if (str_starts_with(strtolower($name), 'namespace\\\\')) {\n"
    "            $relative = substr($name, strlen('namespace\\\\'));\n\n"
    "            return $this->namespace !== '' ? $this->namespace.'\\\\'.$relative : $relative;\n"
    "        }\n\n"
)
context_source = context_source[:pos] + insertion + context_source[pos:]
write('src/Analysis/FileContext.php', context_source)

'''
code = code[:start] + replacement + code[end:]

exec(compile(code, str(path), 'exec'), {'__file__': str(path), '__name__': '__main__'})

# PHPStan-max cleanup for the generated namespace-context implementation.
root = path.resolve().parents[1]

metadata_path = root / 'src/Analysis/ClassMetadataIndex.php'
metadata = metadata_path.read_text()
parse_signature = '    private function parseContext(array $tokens): FileContext\n'
parse_pos = metadata.find(parse_signature)
if parse_pos >= 0:
    doc_pos = metadata.rfind('    /**', 0, parse_pos)
    next_pos = metadata.find('    /** @return array<string, string> */\n    private function parseUseClause', parse_pos)
    if doc_pos < 0 or next_pos < 0:
        raise RuntimeError('unable to remove obsolete parseContext method')
    metadata = metadata[:doc_pos] + metadata[next_pos:]

# FileContextMap now owns namespace/import parsing, so the old local parser
# helpers in ClassMetadataIndex are intentionally removed as dead code.
use_start = metadata.find('    /** @return array<string, string> */\n    private function parseUseClause')
if use_start >= 0:
    use_end = metadata.find('    private function parseSingleName', use_start)
    if use_end < 0:
        raise RuntimeError('unable to remove obsolete parseUseClause/appendUse helpers')
    metadata = metadata[:use_start] + metadata[use_end:]

name_signature = '    private function isNameToken(?int $id): bool\n'
name_pos = metadata.find(name_signature)
if name_pos >= 0:
    name_end = metadata.find('    /**\n     * @param  list<Token>  $tokens\n     */\n    private function previousSignificant', name_pos)
    if name_end < 0:
        raise RuntimeError('unable to remove obsolete isNameToken helper')
    metadata = metadata[:name_pos] + metadata[name_end:]
metadata_path.write_text(metadata)

map_path = root / 'src/Analysis/FileContextMap.php'
map_source = map_path.read_text()
old = "        return $this->ranges[array_key_last($this->ranges)]['context'] ?? new FileContext('', []);"
new = "        $last = array_key_last($this->ranges);\n\n        return $last !== null ? $this->ranges[$last]['context'] : new FileContext('', []);"
if old not in map_source:
    raise RuntimeError('FileContextMap fallback anchor missing')
map_path.write_text(map_source.replace(old, new, 1))

scanner_path = root / 'src/Analysis/SourceScanner.php'
scanner = scanner_path.read_text()
old = """    /** @param array{matches:array<int|string,mixed>} $match */
    private function captured(array $match, string $name): string
    {
        $offset = $match['offset'] ?? null;
        if (is_int($offset)) {
            $this->context = $this->classIndex->contextFor($this->file, $offset);
        }
        $value = $match['matches'][$name] ?? '';
"""
new = """    /** @param array{offset:int,matches:array<int|string,mixed>} $match */
    private function captured(array $match, string $name): string
    {
        $this->context = $this->classIndex->contextFor($this->file, $match['offset']);
        $value = $match['matches'][$name] ?? '';
"""
if old not in scanner:
    raise RuntimeError('SourceScanner captured() generated anchor missing')
scanner_path.write_text(scanner.replace(old, new, 1))

print('v0.3.0 final namespace/release patch applied with PHPStan cleanup')
