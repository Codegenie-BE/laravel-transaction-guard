<?php

declare(strict_types=1);

namespace Codegenie\TransactionGuard\Console;

use Codegenie\TransactionGuard\Analysis\AnalysisConfig;
use Codegenie\TransactionGuard\Analysis\Baseline;
use Codegenie\TransactionGuard\Analysis\Finding;
use Codegenie\TransactionGuard\Analysis\Severity;
use Codegenie\TransactionGuard\TransactionGuard;
use Illuminate\Console\Command;

final class TransactionGuardCommand extends Command
{
    protected $signature = 'transaction:guard
        {paths?* : Files or directories to scan; defaults to configured paths}
        {--format=console : Output format: console, json, github, sarif}
        {--fail-on= : Minimum severity that fails the command: info, warning, error, critical, never}
        {--baseline= : Baseline path; defaults to the configured baseline}
        {--no-baseline : Ignore the baseline}
        {--generate-baseline : Write all current findings to the baseline and exit successfully}';

    protected $description = 'Detect unsafe side effects that can escape Laravel database transaction boundaries.';

    public function handle(): int
    {
        $formatOption = $this->option('format');
        $format = is_string($formatOption) ? strtolower($formatOption) : 'console';
        if (! in_array($format, ['console', 'json', 'github', 'sarif'], true)) {
            $this->error('Invalid --format. Use console, json, github, or sarif.');

            return self::INVALID;
        }

        $paths = $this->stringArguments('paths');
        if ($paths === []) {
            $paths = $this->stringListConfig('transaction-guard.paths', ['app', 'routes']);
        }
        $paths = array_map(fn (string $path): string => $this->absolutePath($path), $paths);

        $exclude = $this->stringListConfig('transaction-guard.exclude');

        try {
            $analysisConfig = new AnalysisConfig(
                defaultQueueConnection: $this->stringConfig('queue.default', 'sync'),
                queueAfterCommitByConnection: $this->queueAfterCommitMap(),
                customSideEffectPatterns: $this->stringListConfig('transaction-guard.custom_side_effect_patterns'),
                disabledRules: $this->stringListConfig('transaction-guard.disabled_rules'),
                detectReadHttpCalls: (bool) config('transaction-guard.detect_read_http_calls', false),
                defaultDatabaseConnection: $this->stringConfig('database.default', '@default'),
                databaseDriverByConnection: $this->databaseDriverMap(),
            );

            $guard = new TransactionGuard($analysisConfig);
            $baselineOption = $this->option('baseline');
            $baselinePath = $this->absolutePath(
                is_string($baselineOption) && $baselineOption !== ''
                    ? $baselineOption
                    : $this->stringConfig('transaction-guard.baseline', '.transaction-guard-baseline.json'),
            );
            $useBaseline = ! (bool) $this->option('no-baseline') && ! (bool) $this->option('generate-baseline');
            $baseline = $useBaseline ? Baseline::load($baselinePath) : null;
            $result = $guard->analyze($paths, $exclude, $baseline);
        } catch (\JsonException|\InvalidArgumentException|\RuntimeException $exception) {
            $this->error('Transaction Guard configuration error: '.$exception->getMessage());

            return self::INVALID;
        }

        if ((bool) $this->option('generate-baseline')) {
            try {
                Baseline::write($baselinePath, $result->findings);
            } catch (\JsonException|\RuntimeException $exception) {
                $this->error('Unable to generate Transaction Guard baseline: '.$exception->getMessage());

                return self::INVALID;
            }

            $this->info(sprintf('Transaction Guard baseline written: %s (%d findings).', $baselinePath, count($result->findings)));

            return self::SUCCESS;
        }

        $this->render($format, $result->findings, $result->filesAnalyzed);

        $failOnOption = $this->option('fail-on');
        $failOn = strtolower(
            is_string($failOnOption) && $failOnOption !== ''
                ? $failOnOption
                : $this->stringConfig('transaction-guard.fail_on', 'warning'),
        );
        if ($failOn === 'never') {
            return self::SUCCESS;
        }

        try {
            $threshold = Severity::fromName($failOn);
        } catch (\InvalidArgumentException) {
            $this->error('Invalid --fail-on. Use info, warning, error, critical, or never.');

            return self::INVALID;
        }

        foreach ($result->findings as $finding) {
            if ($finding->severity->value >= $threshold->value) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    /** @return array<string, bool> */
    private function queueAfterCommitMap(): array
    {
        $override = config('transaction-guard.queue_after_commit');
        $configuredConnections = config('queue.connections', []);
        $connections = is_array($configuredConnections) ? $configuredConnections : [];
        $result = [];

        foreach ($connections as $name => $configuration) {
            $configured = is_array($configuration) ? (bool) ($configuration['after_commit'] ?? false) : false;
            $result[(string) $name] = is_bool($override) ? $override : $configured;
        }

        if ($result === []) {
            $result[$this->stringConfig('queue.default', 'sync')] = is_bool($override) ? $override : false;
        }

        return $result;
    }

    /** @return array<string, string> */
    private function databaseDriverMap(): array
    {
        $configuredConnections = config('database.connections', []);
        if (! is_array($configuredConnections)) {
            return [];
        }

        $drivers = [];
        foreach ($configuredConnections as $name => $configuration) {
            if (! is_array($configuration) || ! isset($configuration['driver']) || ! is_string($configuration['driver'])) {
                continue;
            }
            $drivers[(string) $name] = $configuration['driver'];
        }

        return $drivers;
    }

    /** @param  list<Finding>  $findings */
    private function render(string $format, array $findings, int $filesAnalyzed): void
    {
        if ($format === 'json') {
            $this->line(json_encode([
                'files_analyzed' => $filesAnalyzed,
                'findings' => array_map(static fn (Finding $finding): array => $finding->toArray(), $findings),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));

            return;
        }

        if ($format === 'sarif') {
            $this->line(json_encode($this->sarifPayload($findings), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));

            return;
        }

        if ($format === 'github') {
            foreach ($findings as $finding) {
                $level = $finding->severity->value >= Severity::Error->value ? 'error' : 'warning';
                $message = $this->escapeGithubCommandValue("{$finding->rule}: {$finding->message}");
                $file = $this->escapeGithubCommandValue($this->relativePath($finding->file));
                $this->line(sprintf('::%s file=%s,line=%d::%s', $level, $file, $finding->line, $message));
            }

            return;
        }

        if ($findings === []) {
            $this->info(sprintf('Transaction Guard: no unsafe transaction side effects found in %d PHP files.', $filesAnalyzed));

            return;
        }

        $rows = [];
        foreach ($findings as $finding) {
            $rows[] = [
                strtoupper($finding->severity->label()),
                $finding->rule,
                $this->relativePath($finding->file).':'.$finding->line,
                $finding->message,
            ];
        }

        $this->table(['Severity', 'Rule', 'Location', 'Finding'], $rows);
        $this->newLine();
        $this->warn(sprintf('Transaction Guard found %d issue(s) across %d PHP files.', count($findings), $filesAnalyzed));
    }

    /**
     * @param  list<Finding>  $findings
     * @return array<string, mixed>
     */
    private function sarifPayload(array $findings): array
    {
        $rules = [];
        $results = [];

        foreach ($findings as $finding) {
            $rules[$finding->rule] ??= [
                'id' => $finding->rule,
                'name' => $finding->rule,
                'shortDescription' => ['text' => $finding->message],
                'help' => ['text' => $finding->remediation],
            ];
            $results[] = [
                'ruleId' => $finding->rule,
                'level' => $this->sarifLevel($finding->severity),
                'message' => ['text' => $finding->message],
                'locations' => [[
                    'physicalLocation' => [
                        'artifactLocation' => ['uri' => $this->relativePath($finding->file)],
                        'region' => ['startLine' => $finding->line],
                    ],
                ]],
                'partialFingerprints' => ['transactionGuardFingerprint' => $finding->fingerprint()],
                'properties' => [
                    'severity' => $finding->severity->label(),
                    'confidence' => $finding->confidence,
                    'context' => $finding->context,
                ],
            ];
        }

        return [
            '$schema' => 'https://json.schemastore.org/sarif-2.1.0.json',
            'version' => '2.1.0',
            'runs' => [[
                'tool' => ['driver' => [
                    'name' => 'Laravel Transaction Guard',
                    'informationUri' => 'https://github.com/Codegenie-BE/laravel-transaction-guard',
                    'rules' => array_values($rules),
                ]],
                'results' => $results,
            ]],
        ];
    }

    private function sarifLevel(Severity $severity): string
    {
        return match ($severity) {
            Severity::Critical, Severity::Error => 'error',
            Severity::Warning => 'warning',
            Severity::Info => 'note',
        };
    }

    /** @return list<string> */
    private function stringArguments(string $name): array
    {
        $value = $this->argument($name);

        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    /**
     * @param  list<string>  $default
     * @return list<string>
     */
    private function stringListConfig(string $key, array $default = []): array
    {
        $value = config($key, $default);

        return is_array($value) ? array_values(array_filter($value, 'is_string')) : $default;
    }

    private function stringConfig(string $key, string $default): string
    {
        $value = config($key, $default);

        return is_string($value) ? $value : $default;
    }

    private function escapeGithubCommandValue(string $value): string
    {
        return str_replace(['%', "\r", "\n", ':', ','], ['%25', '%0D', '%0A', '%3A', '%2C'], $value);
    }

    private function absolutePath(string $path): string
    {
        if ($path === '') {
            return base_path();
        }
        if (str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1) {
            return $path;
        }

        return base_path($path);
    }

    private function relativePath(string $path): string
    {
        $base = rtrim(str_replace('\\', '/', base_path()), '/').'/';
        $normalized = str_replace('\\', '/', $path);

        return str_starts_with($normalized, $base) ? substr($normalized, strlen($base)) : $normalized;
    }
}
