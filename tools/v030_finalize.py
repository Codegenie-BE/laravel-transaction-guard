from __future__ import annotations

from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[1]


def read(path: str) -> str:
    return (ROOT / path).read_text()


def write(path: str, content: str) -> None:
    target = ROOT / path
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_text(content)


def replace(path: str, old: str, new: str, count: int = 1) -> None:
    text = read(path)
    if old not in text:
        raise RuntimeError(f'missing anchor in {path}: {old[:120]!r}')
    write(path, text.replace(old, new, count))


# ---------------------------------------------------------------------------
# Offset-aware PHP namespace/import contexts.
# ---------------------------------------------------------------------------
replace('src/Analysis/ClassMetadataIndex.php',
        '/** @var array<string, FileContext> */\n    private array $contexts = [];',
        '/** @var array<string, FileContextMap> */\n    private array $contexts = [];')
replace('src/Analysis/ClassMetadataIndex.php',
'''    public function contextFor(string $file): FileContext
    {
        return $this->contexts[$file] ?? new FileContext('', []);
    }
''',
'''    public function contextFor(string $file, int $offset = 0): FileContext
    {
        return isset($this->contexts[$file])
            ? $this->contexts[$file]->at($offset)
            : new FileContext('', []);
    }

    /** @return list<FileContext> */
    public function contextsFor(string $file): array
    {
        return isset($this->contexts[$file]) ? $this->contexts[$file]->contexts() : [new FileContext('', [])];
    }
''')
replace('src/Analysis/ClassMetadataIndex.php',
'''        $tokens = $this->tokens($source);
        $context = $this->parseContext($tokens);
        $this->contexts[$file] = $context;

        $this->indexInterfaceDeclarations($tokens, $context);
        $this->indexEnumDeclarations($tokens, $context);
        $this->indexQueueRoutes($source, $tokens, $context);
        $this->indexQueueForwards($source, $tokens, $context);
        $this->indexClassAndTraitDeclarations($source, $tokens, $context);
''',
'''        $tokens = $this->tokens($source);
        $contexts = FileContextMap::fromTokens($tokens, strlen($source));
        $this->contexts[$file] = $contexts;

        $this->indexInterfaceDeclarations($tokens, $contexts);
        $this->indexEnumDeclarations($tokens, $contexts);
        $this->indexQueueRoutes($source, $tokens, $contexts);
        $this->indexQueueForwards($source, $tokens, $contexts);
        $this->indexClassAndTraitDeclarations($source, $tokens, $contexts);
''')
replace('src/Analysis/ClassMetadataIndex.php',
        'private function indexClassAndTraitDeclarations(string $source, array $tokens, FileContext $context): void',
        'private function indexClassAndTraitDeclarations(string $source, array $tokens, FileContextMap $contexts): void')
replace('src/Analysis/ClassMetadataIndex.php',
'''            $nameIndex = $this->nextTokenOfType($tokens, $i + 1, T_STRING);
            if ($nameIndex === null) {
                continue;
            }

            $name = $tokens[$nameIndex]['text'];
''',
'''            $nameIndex = $this->nextTokenOfType($tokens, $i + 1, T_STRING);
            if ($nameIndex === null) {
                continue;
            }

            $context = $contexts->at($tokens[$i]['offset']);
            $name = $tokens[$nameIndex]['text'];
''', count=1)
replace('src/Analysis/ClassMetadataIndex.php',
        'private function indexInterfaceDeclarations(array $tokens, FileContext $context): void',
        'private function indexInterfaceDeclarations(array $tokens, FileContextMap $contexts): void')
replace('src/Analysis/ClassMetadataIndex.php',
'''            $nameIndex = $this->nextTokenOfType($tokens, $i + 1, T_STRING);
            if ($nameIndex === null) {
                continue;
            }

            $parents = [];
''',
'''            $nameIndex = $this->nextTokenOfType($tokens, $i + 1, T_STRING);
            if ($nameIndex === null) {
                continue;
            }

            $context = $contexts->at($tokens[$i]['offset']);
            $parents = [];
''', count=1)
# Enum index method was introduced by v0.3.0.
replace('src/Analysis/ClassMetadataIndex.php',
        'private function indexEnumDeclarations(array $tokens, FileContext $context): void',
        'private function indexEnumDeclarations(array $tokens, FileContextMap $contexts): void')
replace('src/Analysis/ClassMetadataIndex.php',
'''            $nameIndex = $this->nextTokenOfType($tokens, $i + 1, T_STRING);
            $open = $nameIndex === null ? null : $this->nextText($tokens, $nameIndex + 1, '{');
''',
'''            $nameIndex = $this->nextTokenOfType($tokens, $i + 1, T_STRING);
            $context = $contexts->at($tokens[$i]['offset']);
            $open = $nameIndex === null ? null : $this->nextText($tokens, $nameIndex + 1, '{');
''', count=1)

# Queue route/forward declarations can live in any namespace section.
replace('src/Analysis/ClassMetadataIndex.php',
        'private function indexQueueRoutes(string $source, array $tokens, FileContext $context): void',
        'private function indexQueueRoutes(string $source, array $tokens, FileContextMap $contexts): void')
replace('src/Analysis/ClassMetadataIndex.php',
'''        foreach ($this->facadeAliases($context, 'Illuminate\\Support\\Facades\\Queue', 'Queue') as $alias) {
            $pattern = '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\\s*::\\s*route\\s*\\(/i';
''',
'''        foreach ($this->facadeAliasesForMap($contexts, 'Illuminate\\Support\\Facades\\Queue', 'Queue') as $alias) {
            $pattern = '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\\s*::\\s*route\\s*\\(/i';
''', count=1)
replace('src/Analysis/ClassMetadataIndex.php',
'''            foreach ($matches[0] as [$matched, $offset]) {
                if ($this->offsetIsNonCode($tokens, $offset)) {
                    continue;
                }
''',
'''            foreach ($matches[0] as [$matched, $offset]) {
                $context = $contexts->at($offset);
                if (! in_array($alias, $this->facadeAliases($context, 'Illuminate\\Support\\Facades\\Queue', 'Queue'), true)
                    || $this->offsetIsNonCode($tokens, $offset)) {
                    continue;
                }
''', count=1)
replace('src/Analysis/ClassMetadataIndex.php',
        'private function indexQueueForwards(string $source, array $tokens, FileContext $context): void',
        'private function indexQueueForwards(string $source, array $tokens, FileContextMap $contexts): void')
# second facadeAliases Queue occurrence (forward)
old = "        foreach ($this->facadeAliases($context, 'Illuminate\\\\Support\\\\Facades\\\\Queue', 'Queue') as $alias) {\n            $pattern = '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\\\\s*::\\\\s*forward\\\\s*\\\\(/i';"
new = "        foreach ($this->facadeAliasesForMap($contexts, 'Illuminate\\\\Support\\\\Facades\\\\Queue', 'Queue') as $alias) {\n            $pattern = '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\\\\s*::\\\\s*forward\\\\s*\\\\(/i';"
replace('src/Analysis/ClassMetadataIndex.php', old, new, count=1)
# forward match loop: replace the next plain block after forward signature.
text = read('src/Analysis/ClassMetadataIndex.php')
forward_pos = text.index('private function indexQueueForwards')
needle = "            foreach ($matches[0] as [$matched, $offset]) {\n                if ($this->offsetIsNonCode($tokens, $offset)) {\n                    continue;\n                }"
pos = text.index(needle, forward_pos)
replacement = "            foreach ($matches[0] as [$matched, $offset]) {\n                $context = $contexts->at($offset);\n                if (! in_array($alias, $this->facadeAliases($context, 'Illuminate\\\\Support\\\\Facades\\\\Queue', 'Queue'), true)\n                    || $this->offsetIsNonCode($tokens, $offset)) {\n                    continue;\n                }"
text = text[:pos] + replacement + text[pos + len(needle):]
write('src/Analysis/ClassMetadataIndex.php', text)

# Add map-wide facade aliases before the existing per-context helper.
replace('src/Analysis/ClassMetadataIndex.php',
'''    /** @return list<string> */
    private function facadeAliases(FileContext $context, string $fqcn, string $fallback): array
''',
'''    /** @return list<string> */
    private function facadeAliasesForMap(FileContextMap $contexts, string $fqcn, string $fallback): array
    {
        $aliases = [];
        foreach ($contexts->contexts() as $context) {
            $aliases = array_merge($aliases, $this->facadeAliases($context, $fqcn, $fallback));
        }

        return array_values(array_unique($aliases));
    }

    /** @return list<string> */
    private function facadeAliases(FileContext $context, string $fqcn, string $fallback): array
''')
# T_NAME_RELATIVE is a valid PHP name token.
replace('src/Analysis/ClassMetadataIndex.php',
'''            defined('T_NAME_FULLY_QUALIFIED') ? T_NAME_FULLY_QUALIFIED : null,
            defined('T_NS_SEPARATOR') ? T_NS_SEPARATOR : null,
''',
'''            defined('T_NAME_FULLY_QUALIFIED') ? T_NAME_FULLY_QUALIFIED : null,
            defined('T_NAME_RELATIVE') ? T_NAME_RELATIVE : null,
            defined('T_NS_SEPARATOR') ? T_NS_SEPARATOR : null,
''')

# FileContext understands namespace-relative names.
replace('src/Analysis/FileContext.php',
'''        if ($name[0] === '\\') {
            return ltrim($name, '\\');
        }

        [$first] = explode('\\', $name, 2);
''',
'''        if ($name[0] === '\\') {
            return ltrim($name, '\\');
        }
        if (str_starts_with(strtolower($name), 'namespace\\')) {
            $relative = substr($name, strlen('namespace\\'));

            return $this->namespace !== '' ? $this->namespace.'\\'.$relative : $relative;
        }

        [$first] = explode('\\', $name, 2);
''')

# SourceScanner chooses context by match offset and sees aliases from all sections.
replace('src/Analysis/SourceScanner.php',
'''    private function captured(array $match, string $name): string
    {
        $value = $match['matches'][$name] ?? '';
''',
'''    private function captured(array $match, string $name): string
    {
        $offset = $match['offset'] ?? null;
        if (is_int($offset)) {
            $this->context = $this->classIndex->contextFor($this->file, $offset);
        }
        $value = $match['matches'][$name] ?? '';
''')
# Replace facadeAliases body with map-aware union.
pattern = re.compile(r"    /\*\* @return list<string> \*/\n    private function facadeAliases\(string \$fqcn, string \$fallback\): array\n    \{.*?\n    \}\n\n    /\*\* @param  list<Finding>", re.S)
source = read('src/Analysis/SourceScanner.php')
match = pattern.search(source)
if not match:
    raise RuntimeError('SourceScanner facadeAliases block not found')
replacement = r'''    /** @return list<string> */
    private function facadeAliases(string $fqcn, string $fallback): array
    {
        $cacheKey = strtolower(ltrim($fqcn, '\\')).'|'.$fallback;
        if (isset($this->facadeAliasCache[$cacheKey])) {
            return $this->facadeAliasCache[$cacheKey];
        }

        $normalized = ltrim($fqcn, '\\');
        $aliases = ['\\'.$normalized];
        foreach ($this->classIndex->contextsFor($this->file) as $context) {
            $fallbackImport = $context->imports[$fallback] ?? null;
            if ($fallbackImport === null || strcasecmp(ltrim($fallbackImport, '\\'), $normalized) === 0) {
                $aliases[] = $fallback;
            }
            foreach ($context->imports as $alias => $import) {
                if (strcasecmp(ltrim($import, '\\'), $normalized) === 0) {
                    $aliases[] = $alias;
                }
            }
        }

        return $this->facadeAliasCache[$cacheKey] = array_values(array_unique($aliases));
    }

    /** @param  list<Finding>'''
source = source[:match.start()] + replacement + source[match.end():]
write('src/Analysis/SourceScanner.php', source)

# Manual dependency-free runners know about the extracted context map.
for path in ['tools/smoke.php', 'tools/benchmark.php']:
    text = read(path)
    if "'src/Analysis/FileContextMap.php'" not in text:
        text = text.replace("    'src/Analysis/FileContext.php',\n", "    'src/Analysis/FileContext.php',\n    'src/Analysis/FileContextMap.php',\n", 1)
        write(path, text)

# Add multiple/bracketed namespace regression scenarios.
scenario_path = 'tests/Support/Scenarios/V030Hardening.php'
text = read(scenario_path)
insert = r'''
    'multiple unbracketed namespaces use the correct imports' => [
        'code' => <<<'PHP'
<?php
namespace App\First;
class Placeholder {}
namespace App\Second;
use Illuminate\Support\Facades\DB as Database;
use Illuminate\Support\Facades\Http as Client;
Database::transaction(function () { Client::post('https://example.test'); });
PHP,
        'rules' => ['TG006'],
    ],
    'bracketed namespace imports are analyzed' => [
        'code' => <<<'PHP'
<?php
namespace App\First { class Placeholder {} }
namespace App\Second {
    use Illuminate\Support\Facades\DB as Database;
    use Illuminate\Support\Facades\Http as Client;
    Database::transaction(function () { Client::post('https://example.test'); });
}
PHP,
        'rules' => ['TG006'],
    ],
    'second namespace Eloquent metadata uses its own context' => [
        'code' => <<<'PHP'
<?php
namespace App\First;
class Placeholder {}
namespace App\Second;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB as Database;
class Audit extends Model { protected $connection = 'pgsql'; }
Database::connection('mysql')->transaction(function () { Audit::create(['ok' => 1]); });
PHP,
        'rules' => ['TG021'],
        'config' => ['database_default' => 'mysql'],
    ],
'''
pos = text.rfind('];')
if pos < 0:
    raise RuntimeError('scenario file closing array missing')
text = text[:pos] + insert + text[pos:]
write(scenario_path, text)

# Design documentation reflects the targeted extraction.
replace('docs/DESIGN.md',
'''The analyzer builds a lightweight class metadata index for imports, inheritance, implemented interfaces, constructor `afterCommit()` / `beforeCommit()` intent, literal job queue connections, and literal Laravel 13 `Queue::route()` connection rules. It then maps transaction regions and examines only effects that lexically execute inside those regions.
''',
'''The analyzer builds a lightweight class metadata index for imports, inheritance, implemented interfaces, constructor `afterCommit()` / `beforeCommit()` intent, literal job queue connections, and Laravel 13 queue routing. `FileContextMap` keeps namespace/import resolution offset-aware for multi-namespace and bracketed-namespace files, while `SourceIndex` owns source-location/non-code lookups. The scanner then maps transaction regions and examines only effects that lexically execute inside those regions.
''')

# ---------------------------------------------------------------------------
# CI/release: tests only test; validated main changes create a tag in a separate
# workflow; tag workflow owns release/archive/Packagist publication checks.
# ---------------------------------------------------------------------------
tests = read('.github/workflows/tests.yml')
release_marker = '\n  release:\n'
if release_marker in tests:
    tests = tests.split(release_marker, 1)[0].rstrip() + '\n'
write('.github/workflows/tests.yml', tests)

write('.github/workflows/publish.yml', r'''name: Publish version tag

on:
  workflow_run:
    workflows:
      - Tests
    types:
      - completed

permissions:
  contents: write

concurrency:
  group: publish-version-tag
  cancel-in-progress: false

jobs:
  tag:
    if: >-
      github.event.workflow_run.conclusion == 'success' &&
      github.event.workflow_run.event == 'push' &&
      github.event.workflow_run.head_branch == 'main'
    runs-on: ubuntu-latest
    timeout-minutes: 10
    steps:
      - name: Checkout validated main commit
        uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1
        with:
          ref: ${{ github.event.workflow_run.head_sha }}
          fetch-depth: 0

      - name: Publish newly introduced changelog version
        shell: bash
        run: |
          set -euo pipefail
          head_sha='${{ github.event.workflow_run.head_sha }}'
          tag="$(sed -nE 's/^## \[(v[0-9]+\.[0-9]+\.[0-9]+)\] - [0-9]{4}-[0-9]{2}-[0-9]{2}$/\1/p' CHANGELOG.md | head -n 1)"
          test -n "${tag}"

          parent="$(git rev-parse "${head_sha}^")"
          if ! git diff --unified=0 "${parent}" "${head_sha}" -- CHANGELOG.md | grep -F "+## [${tag}]" >/dev/null; then
            echo "No new changelog version introduced by ${head_sha}; no tag required."
            exit 0
          fi

          if git rev-parse -q --verify "refs/tags/${tag}" >/dev/null; then
            existing="$(git rev-list -n 1 "${tag}")"
            if [ "${existing}" != "${head_sha}" ]; then
              echo "Tag ${tag} already exists at ${existing}, expected ${head_sha}." >&2
              exit 1
            fi
            echo "${tag} already points at the validated commit."
            exit 0
          fi

          git config user.name 'github-actions[bot]'
          git config user.email '41898282+github-actions[bot]@users.noreply.github.com'
          git tag --annotate "${tag}" "${head_sha}" --message "${tag}"
          git push origin "refs/tags/${tag}"
''')

write('.github/workflows/release.yml', r'''name: Release

on:
  push:
    tags:
      - 'v*'

permissions:
  contents: write

concurrency:
  group: release-${{ github.ref }}
  cancel-in-progress: false

jobs:
  release:
    runs-on: ubuntu-latest
    timeout-minutes: 25
    steps:
      - name: Checkout release tag
        uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1
        with:
          fetch-depth: 0

      - name: Setup PHP
        uses: shivammathur/setup-php@f3e473d116dcccaddc5834248c87452386958240
        with:
          php-version: '8.5'
          coverage: xdebug
          tools: composer:v2

      - name: Verify tag and changelog
        shell: bash
        run: |
          set -euo pipefail
          test "$(git rev-parse HEAD)" = "${GITHUB_SHA}"
          grep -Eq "^## \[${GITHUB_REF_NAME}\] - [0-9]{4}-[0-9]{2}-[0-9]{2}$" CHANGELOG.md

      - name: Install release validation dependencies
        run: composer update --prefer-stable --prefer-dist --with-all-dependencies --no-interaction --no-progress

      - name: Run complete quality gate
        run: composer check:all

      - name: Enforce coverage baseline
        run: composer test:coverage

      - name: Validate distribution archive
        shell: bash
        run: |
          set -euo pipefail
          rm -rf build
          composer archive --format=zip --dir=build
          archive="$(find build -maxdepth 1 -type f -name '*.zip' | head -n 1)"
          test -n "${archive}"
          unzip -l "${archive}" > /tmp/transaction-guard-archive.txt
          if grep -E '(^|/)(tests|tools|docs|\.github|\.audit)/' /tmp/transaction-guard-archive.txt; then
            echo 'Release archive contains development-only files.' >&2
            exit 1
          fi

      - name: Ensure GitHub release exists
        env:
          GH_TOKEN: ${{ github.token }}
        run: |
          if gh release view "${GITHUB_REF_NAME}" >/dev/null 2>&1; then
            echo "GitHub release ${GITHUB_REF_NAME} already exists."
          else
            gh release create "${GITHUB_REF_NAME}" --verify-tag --generate-notes --title "${GITHUB_REF_NAME}"
          fi

      - name: Require Packagist visibility
        shell: bash
        run: |
          set -euo pipefail
          endpoint='https://repo.packagist.org/p2/codegenie-be/laravel-transaction-guard.json'
          for attempt in {1..12}; do
            if response="$(curl --fail --silent --show-error --location "${endpoint}" 2>/dev/null)"; then
              if jq --exit-status \
                --arg tag "${GITHUB_REF_NAME}" \
                --arg commit "${GITHUB_SHA}" \
                '.packages["codegenie-be/laravel-transaction-guard"] | any(.version == $tag and .source.reference == $commit)' \
                <<< "${response}" >/dev/null; then
                echo "Packagist exposes ${GITHUB_REF_NAME} at ${GITHUB_SHA}."
                exit 0
              fi
            fi
            echo "Waiting for Packagist visibility (${attempt}/12)..."
            sleep 10
          done
          echo "Packagist did not expose ${GITHUB_REF_NAME} at ${GITHUB_SHA}." >&2
          exit 1
''')

# Branch-protection policy is now explicit in-repo; the GitHub repository setting
# itself is applied separately when an administrative connector endpoint exists.
write('.github/CODEOWNERS', '* @Codegenie-BE\n')
write('docs/BRANCH-PROTECTION.md', '''# Main branch policy\n\n`main` is the release branch. Changes should enter through a pull request after the `Tests` workflow succeeds.\n\nRequired release gates:\n\n- all PHP/Laravel quality-matrix jobs;\n- `Lowest supported dependencies`;\n- `Coverage / PHP 8.5 / Laravel 13`;\n- no force-pushes or branch deletion;\n- Codegenie ownership review for repository changes.\n\nThe repository workflows are designed so release tagging only happens after a successful `Tests` run on `main`; tag publication is isolated from cancel-in-progress test concurrency.\n''')

# Remove temporary audit/writer payloads from the release tree. The current
# script may unlink itself safely after Python has loaded it.
for relative in [
    '.github/workflows/v030-maintenance.yml',
    '.v030-trigger', '.v030-trigger-2', '.v030-trigger-3', '.v030-trigger-4', '.v030-trigger-5',
    'tools/v030_patch.py', 'tools/v030_patch_runner.py',
]:
    target = ROOT / relative
    if target.exists():
        target.unlink()

# Keep the finalizer until the validated writer commits this change; it is
# removed in the following repository-cleanup commit before final PR checks.

print('v0.3.0 final namespace/release patch applied')
