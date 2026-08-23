<?php

declare(strict_types=1);

namespace Codegenie\TransactionGuard;

use Codegenie\TransactionGuard\Analysis\AnalysisConfig;
use Codegenie\TransactionGuard\Analysis\AnalysisResult;
use Codegenie\TransactionGuard\Analysis\Baseline;
use Codegenie\TransactionGuard\Analysis\ClassMetadataIndex;
use Codegenie\TransactionGuard\Analysis\Finding;
use Codegenie\TransactionGuard\Analysis\RedisFindingRefiner;
use Codegenie\TransactionGuard\Analysis\RuleCatalog;
use Codegenie\TransactionGuard\Analysis\Severity;
use Codegenie\TransactionGuard\Analysis\SourceScanner;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class TransactionGuard
{
    /** @var array<string, Finding> */
    private array $discoveryDiagnostics = [];

    public function __construct(private readonly AnalysisConfig $config = new AnalysisConfig) {}

    /**
     * @param  list<string>  $paths
     * @param  list<string>  $excludePatterns
     */
    public function analyze(array $paths, array $excludePatterns = [], ?Baseline $baseline = null): AnalysisResult
    {
        $this->discoveryDiagnostics = [];
        $files = $this->discoverPhpFiles($paths, $excludePatterns);
        if ($files === [] && $this->discoveryDiagnostics === [] && ! $this->config->allowEmptyScan) {
            throw new \InvalidArgumentException('Transaction Guard did not discover any PHP files to analyze.');
        }
        $index = ClassMetadataIndex::fromFiles($files);
        $scanner = new SourceScanner($index, $this->config);
        $findings = [];
        $diagnostics = array_values($this->discoveryDiagnostics);
        $baselineOccurrences = [];

        foreach ($files as $file) {
            foreach ($scanner->scan($file) as $finding) {
                $finding = RedisFindingRefiner::refine($finding);
                if ($finding === null) {
                    continue;
                }
                if (RuleCatalog::isDiagnostic($finding->rule)) {
                    $diagnostics[] = $finding;

                    continue;
                }
                if ($baseline !== null) {
                    $fingerprint = $finding->fingerprint();
                    $occurrence = ($baselineOccurrences[$fingerprint] ?? 0) + 1;
                    $baselineOccurrences[$fingerprint] = $occurrence;

                    if ($baseline->contains($finding, $occurrence)) {
                        continue;
                    }
                }

                $findings[] = $finding;
            }
        }

        usort($findings, static fn (Finding $a, Finding $b): int => [str_replace('\\', '/', $a->file), $a->line, -$a->severity->value] <=> [str_replace('\\', '/', $b->file), $b->line, -$b->severity->value]);

        usort($diagnostics, static fn (Finding $a, Finding $b): int => [str_replace('\\', '/', $a->file), $a->line, $a->rule] <=> [str_replace('\\', '/', $b->file), $b->line, $b->rule]);

        return new AnalysisResult($findings, count($files), $diagnostics);
    }

    /**
     * @param  list<string>  $paths
     * @param  list<string>  $excludePatterns
     * @return list<string>
     *
     * @phpstan-impure
     */
    public function discoverPhpFiles(array $paths, array $excludePatterns = []): array
    {
        $files = [];

        foreach ($paths as $path) {
            if (! file_exists($path) && ! $this->excluded($path, $excludePatterns)) {
                throw new \InvalidArgumentException("Scan path does not exist [{$path}].");
            }
            if (is_file($path) && str_ends_with(strtolower($path), '.php')) {
                if (! $this->excluded($path, $excludePatterns)) {
                    $files[] = realpath($path) ?: $path;
                }

                continue;
            }

            if (! is_dir($path) || $this->excluded($path, $excludePatterns)) {
                continue;
            }

            try {
                $directory = new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS);
            } catch (\UnexpectedValueException $exception) {
                $this->recordDiscoveryDiagnostic($path, $exception->getMessage());

                continue;
            }
            $filter = new RecursiveCallbackFilterIterator(
                $directory,
                function (SplFileInfo $entry) use ($excludePatterns): bool {
                    if ($entry->isDir() && ! is_readable($entry->getPathname())) {
                        $this->recordDiscoveryDiagnostic($entry->getPathname(), 'Directory is not readable.');

                        return false;
                    }

                    return ! $this->excluded($entry->getPathname(), $excludePatterns);
                },
            );
            $iterator = new RecursiveIteratorIterator(
                $filter,
                RecursiveIteratorIterator::LEAVES_ONLY,
                RecursiveIteratorIterator::CATCH_GET_CHILD,
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (! $file->isFile() || strtolower($file->getExtension()) !== 'php') {
                    continue;
                }
                $filename = $file->getPathname();
                if (! $this->excluded($filename, $excludePatterns)) {
                    $files[] = realpath($filename) ?: $filename;
                }
            }
        }

        $files = array_values(array_unique($files));
        sort($files);

        return $files;
    }

    private function recordDiscoveryDiagnostic(string $path, string $message): void
    {
        $normalized = realpath($path) ?: $path;
        $this->discoveryDiagnostics[$normalized] ??= new Finding(
            rule: 'TG903',
            severity: Severity::Error,
            message: 'Unable to traverse requested source path: '.$message,
            file: $normalized,
            line: 1,
            snippet: '',
            remediation: 'Fix source-directory permissions or exclusions so Transaction Guard can analyze the complete requested tree.',
            confidence: 'high',
            projectRoot: $this->config->projectRoot,
        );
    }

    /** @param  list<string>  $patterns */
    private function excluded(string $path, array $patterns): bool
    {
        $normalized = trim(str_replace('\\', '/', $path), '/');
        $segments = explode('/', $normalized);

        foreach ($patterns as $pattern) {
            $pattern = trim(str_replace('\\', '/', trim($pattern)), '/');
            if ($pattern === '') {
                continue;
            }

            if (strpbrk($pattern, '*?[') !== false) {
                if (fnmatch($pattern, $normalized) || fnmatch('*/'.$pattern, $normalized) || fnmatch('*/'.$pattern.'/*', $normalized)) {
                    return true;
                }

                continue;
            }

            if (! str_contains($pattern, '/')) {
                if (in_array($pattern, $segments, true)) {
                    return true;
                }

                continue;
            }

            $haystack = '/'.$normalized.'/';
            $needle = '/'.$pattern.'/';
            if ($normalized === $pattern || str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
