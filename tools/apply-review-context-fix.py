from pathlib import Path


def replace_once(source: str, old: str, new: str, label: str) -> str:
    count = source.count(old)
    if count != 1:
        raise SystemExit(f"{label}: expected exactly one match, found {count}")
    return source.replace(old, new, 1)


path = Path('src/Analysis/SourceScanner.php')
source = path.read_text()

source = replace_once(
    source,
    "    /** @var array<string, list<array{fqcn:string,fallback:string}>> */\n"
    "    private array $facadeAliasTargets = [];\n",
    "    /** @var array<string, array{fqcn:string,fallback:string}> */\n"
    "    private array $activeFacadeAliasTargets = [];\n",
    'active facade target property',
)
source = replace_once(
    source,
    "        $this->facadeAliasTargets = [];\n",
    "        $this->activeFacadeAliasTargets = [];\n",
    'active facade target reset',
)

start_marker = "    /** @return list<string> */\n    private function facadeAliases(string $fqcn, string $fallback): array\n"
end_marker = "    private function facadeAliasValidAt(string $alias, string $fqcn, string $fallback, int $offset): bool\n"
start = source.find(start_marker)
end = source.find(end_marker, start)
if start < 0 or end < 0:
    raise SystemExit('facade alias block markers not found')

replacement = r'''    /** @return list<string> */
    private function facadeAliases(string $fqcn, string $fallback): array
    {
        $cacheKey = strtolower(ltrim($fqcn, '\\')).'|'.$fallback;
        $normalized = ltrim($fqcn, '\\');

        if (isset($this->facadeAliasCache[$cacheKey])) {
            $aliases = $this->facadeAliasCache[$cacheKey];
            $this->activateFacadeAliasTargets($aliases, $normalized, $fallback);

            return $aliases;
        }

        $aliases = ['\\'.$normalized];
        foreach ($this->classIndex->contextsFor($this->file) as $context) {
            $fallbackImport = $context->importForAlias($fallback);
            if ($fallbackImport === null || strcasecmp(ltrim($fallbackImport, '\\'), $normalized) === 0) {
                $aliases[] = $fallback;
            }
            foreach ($context->imports as $alias => $import) {
                if (strcasecmp(ltrim($import, '\\'), $normalized) === 0) {
                    $aliases[] = $alias;
                }
            }
        }

        $aliases = array_values(array_unique($aliases));
        $this->activateFacadeAliasTargets($aliases, $normalized, $fallback);

        return $this->facadeAliasCache[$cacheKey] = $aliases;
    }

    /** @param list<string> $aliases */
    private function activateFacadeAliasTargets(array $aliases, string $fqcn, string $fallback): void
    {
        $this->activeFacadeAliasTargets = [];
        foreach ($aliases as $alias) {
            $this->activeFacadeAliasTargets[strtolower(ltrim($alias, '\\'))] = [
                'fqcn' => $fqcn,
                'fallback' => $fallback,
            ];
        }
    }

'''
source = source[:start] + replacement + source[end:]

start_marker = "    /** @param array<int|string, mixed> $match */\n    private function staticFacadeMatchUsesValidContext"
end_marker = "    private function resolveClassAt(string $class, int $offset): string\n"
start = source.find(start_marker)
end = source.find(end_marker, start)
if start < 0 or end < 0:
    raise SystemExit('static facade validation block markers not found')

replacement = r'''    /** @param array<int|string, mixed> $match */
    private function staticFacadeMatchUsesValidContext(string $pattern, array $match, int $offset): bool
    {
        $full = $match[0] ?? null;
        if (! is_array($full) || ! isset($full[0]) || ! is_string($full[0])) {
            return true;
        }

        $separator = strpos($full[0], '::');
        if ($separator === false) {
            return true;
        }
        $alias = trim(substr($full[0], 0, $separator));
        $normalizedAlias = ltrim($alias, '\\');
        if ($normalizedAlias === '') {
            return true;
        }
        foreach (explode('\\', $normalizedAlias) as $segment) {
            if ($segment === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $segment) !== 1) {
                return true;
            }
        }

        // Generic class-static matchers also flow through matches(). Only enforce facade
        // binding when the current regex literally embeds the alias returned by facadeAliases().
        if (! str_contains($pattern, preg_quote($alias, '/'))) {
            return true;
        }

        $target = $this->activeFacadeAliasTargets[strtolower($normalizedAlias)] ?? null;
        if ($target === null) {
            return true;
        }

        return $this->facadeAliasValidAt($alias, $target['fqcn'], $target['fallback'], $offset);
    }

'''
source = source[:start] + replacement + source[end:]

if 'facadeAliasTargets' in source:
    raise SystemExit('stale facadeAliasTargets reference remains')

path.write_text(source)
