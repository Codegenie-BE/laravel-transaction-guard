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
        {--format=console : Output format: console, json, github}
        {--fail-on= : Minimum severity that fails the command: info, warning, error, critical, never}
        {--baseline= : Baseline path; defaults to the configured baseline}
        {--no-baseline : Ignore the baseline}
        {--generate-baseline : Write all current findings to the baseline and exit successfully}';

    protected $description = 'Detect unsafe side effects that can escape Laravel database transaction boundaries.';

    public function handle(): int
    {
        $formatOption = $this->option('format');
        $format = is_string($formatOption) ? strtolower($formatOption) : 'console';
        if (! in_array($format, ['console', 'json', 'github'], true)) {
            $this->error('Invalid --format. Use console, json, or github.');

            return self::INVALID;
        }

        $paths = array_values(array_filter((array) $this->argument('paths'), 'is_string'));
        if ($paths === []) {
            $paths = array_values((array) config('transaction-guard.paths', ['app', 'routes']));
        }
        $paths = array_map(fn (string $path): string => $this->absolutePath($path), $paths);

        $exclude = array_values(array_filter((array) config('transaction-guard.exclude', []), 'is_string'));

        try {
            $analysisConfig = new AnalysisConfig(
                defaultQueueConnection: (string) config('queue.default', 'sync'),
                queueAfterCommitByConnection: $this->queueAfterCommitMap(),
                customSideEffectPatterns: array_values(array_filter((array) config('transaction-guard.custom_side_effect_patterns', []), 'is_string')),
                disabledRules: array_values(array_filter((array) config('transaction-guard.disabled_rules', []), 'is_string')),
                detectReadHttpCalls: (bool) config('transaction-guard.detect_read_http_calls', false),
                defaultDatabaseConnection: (string) config('database.default', '@default'),
            );

            $guard = new TransactionGuard($analysisConfig);
            $baselinePath = $this->absolutePath((string) ($this->option('baseline') ?: config('transaction-guard.baseline', '.transaction-guard-baseline.json')));
            $useBaseline = ! (bool) $this->option('no-baseline') && ! (bool) $this->option('generate-baseline');
            $baseline = $useBaseline ? Baseline::load($baselinePath) : null;
            $result = $guard->analyze($paths, $exclude, $baseline);
        } catch (\JsonException|\InvalidArgumentException|\RuntimeException $exception) {
            $this->error('Transaction Guard configuration error: '.$exception->getMessage());

            return self::INVALID;
        }

        if ((bool) $this->option('generate-baseline')) {
            try {
                $raw = $guard->analyze($paths, $exclude, null);
                Baseline::write($baselinePath, $raw->findings);
            } catch (\JsonException|\RuntimeException $exception) {
                $this->error('Unable to generate Transaction Guard baseline: '.$exception->getMessage());

                return self::INVALID;
            }

            $this->info(sprintf('Transaction Guard baseline written: %s (%d findings).', $baselinePath, count($raw->findings)));

            return self::SUCCESS;
        }

        $this->render($format, $result->findings, $result->filesAnalyzed);

        $failOn = strtolower((string) ($this->option('fail-on') ?: config('transaction-guard.fail_on', 'warning')));
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
        $connections = (array) config('queue.connections', []);
        $result = [];

        foreach ($connections as $name => $configuration) {
            $configured = is_array($configuration) ? (bool) ($configuration['after_commit'] ?? false) : false;
            $result[(string) $name] = is_bool($override) ? $override : $configured;
        }

        if ($result === []) {
            $result[(string) config('queue.default', 'sync')] = is_bool($override) ? $override : false;
        }

        return $result;
    }

    /** @param list<Finding> $findings */
    private function render(string $format, array $findings, int $filesAnalyzed): void
    {
        if ($format === 'json') {
            $this->line(json_encode([
                'files_analyzed' => $filesAnalyzed,
                'findings' => array_map(static fn (Finding $finding): array => $finding->toArray(), $findings),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

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
