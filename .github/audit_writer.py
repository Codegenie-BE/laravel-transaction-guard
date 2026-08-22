from __future__ import annotations

import subprocess
from pathlib import Path

meta = Path('src/Analysis/ClassMetadataIndex.php')
text = meta.read_text()
signature = "    private function queueNameFor(string $class, array $seen = []): ?string\n"
doc = "    /** @param  array<string, true>  $seen */\n"
if doc + signature not in text:
    if signature not in text:
        raise SystemExit('queueNameFor signature not found')
    text = text.replace(signature, doc + signature, 1)
meta.write_text(text)

source = Path('src/Analysis/SourceScanner.php')
text = source.read_text()
start = text.index('    private function scanNotifications')
end = text.index('    private function scanBroadcasts', start)
section = text[start:end]
if '$metadata?->queueAfterCommit()' not in section:
    raise SystemExit('notification queueAfterCommit target not found')
section = section.replace('$metadata?->queueAfterCommit()', '$metadata->queueAfterCommit()', 1)
text = text[:start] + section + text[end:]

old_flush = '''            $flush = function () use (&$regions, &$groupStart, &$groupEnd, &$depth): void {
                if ($groupStart === null) {
                    return;
                }

                $end = $groupEnd ?? strlen($this->source);
                $regions[] = [
                    'start' => $groupStart['end'],
                    'end' => $end,
                    'line' => $this->lineAtOffset($groupStart['offset']),
                    'type' => 'manual',
                    'attempts' => 1,
                    'connection' => $groupStart['connection'],
                    'callableStart' => $groupStart['end'],
                    'callableEnd' => $end,
                ];

                $groupStart = null;
                $groupEnd = null;
                $depth = 0;
            };
'''
new_flush = '''            /** @param array{type:string,offset:int,end:int,scope:string,connection:string}|null $start */
            $flush = function (?array $start, ?int $endOffset) use (&$regions): void {
                if ($start === null) {
                    return;
                }
                if (! isset($start['offset'], $start['end'], $start['connection'])
                    || ! is_int($start['offset'])
                    || ! is_int($start['end'])
                    || ! is_string($start['connection'])) {
                    return;
                }

                $end = $endOffset ?? strlen($this->source);
                $regions[] = [
                    'start' => $start['end'],
                    'end' => $end,
                    'line' => $this->lineAtOffset($start['offset']),
                    'type' => 'manual',
                    'attempts' => 1,
                    'connection' => $start['connection'],
                    'callableStart' => $start['end'],
                    'callableEnd' => $end,
                ];
            };
'''
if old_flush not in text:
    raise SystemExit('manual transaction flush block not found')
text = text.replace(old_flush, new_flush, 1)
old_call = "                        $flush();\n                    }\n                    if ($groupStart === null) {"
new_call = "                        $flush($groupStart, $groupEnd);\n                        $groupStart = null;\n                        $groupEnd = null;\n                        $depth = 0;\n                    }\n                    if ($groupStart === null) {"
if old_call not in text:
    raise SystemExit('nested manual flush call not found')
text = text.replace(old_call, new_call, 1)
old_final = "            $flush();\n        }\n\n        return $regions;"
new_final = "            $flush($groupStart, $groupEnd);\n        }\n\n        return $regions;"
if old_final not in text:
    raise SystemExit('final manual flush call not found')
text = text.replace(old_final, new_final, 1)

old_captured = '''        $value = $match['matches'][$name] ?? '';
        if (is_array($value)) {
            return (string) $value[0];
        }

        return (string) $value;
'''
new_captured = '''        $value = $match['matches'][$name] ?? '';
        if (is_array($value)) {
            $captured = $value[0] ?? '';

            return is_string($captured) ? $captured : '';
        }

        return is_string($value) ? $value : '';
'''
if old_captured not in text:
    raise SystemExit('captured helper not found')
source.write_text(text.replace(old_captured, new_captured, 1))

Path('phpstan.neon').write_text(
    "includes:\n"
    "    - vendor/larastan/larastan/extension.neon\n\n"
    "parameters:\n"
    "    level: max\n"
    "    paths:\n"
    "        - src\n"
    "    tmpDir: .phpstan-cache\n"
    "    reportUnmatchedIgnoredErrors: true\n"
)

Path('.audit-request').unlink(missing_ok=True)
restored = subprocess.run(
    ['git', 'show', 'origin/main:.github/audit_writer.py'],
    check=True,
    capture_output=True,
    text=True,
).stdout
Path('.github/audit_writer.py').write_text(restored)
