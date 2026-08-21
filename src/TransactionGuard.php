<?php

declare(strict_types=1);

namespace Codegenie\TransactionGuard;

use Codegenie\TransactionGuard\Analysis\AnalysisConfig;
use Codegenie\TransactionGuard\Analysis\AnalysisResult;
use Codegenie\TransactionGuard\Analysis\Baseline;
use Codegenie\TransactionGuard\Analysis\ClassMetadataIndex;
use Codegenie\TransactionGuard\Analysis\Finding;
use Codegenie\TransactionGuard\Analysis\SourceScanner;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class TransactionGuard
{
    public function __construct(private readonly AnalysisConfig $config = new AnalysisConfig) {}

    /**
     * @param  list<string>  $paths
     * @param  list<string>  $excludePatterns
     */
    public function analyze(array $paths, array $excludePatterns = [], ?Baseline $baseline = null): AnalysisResult
    {
        $files = $this->discoverPhpFiles($paths, $excludePatterns);
        $index = ClassMetadataIndex::fromFiles($files);
        $scanner = new SourceScanner($index, $this->config);
        $findings = [];

        foreach ($files as $file) {
            foreach ($scanner->scan($file) as $finding) {
                if ($baseline?->contains($finding) === true) {
                    continue;
                }
                $findings[] = $finding;
            }
        }

        usort($findings, static fn (Finding $a, Finding $b): int => [str_replace('\\', '/', $a->file), $a->line, -$a->severity->value] <=> [str_replace('\\', '/', $b->file), $b->line, -$b->severity->value]);

        return new AnalysisResult($findings, count($files));
    }

    /**
     * @param  list<string>  $paths
     * @param  list<string>  $excludePatterns
     * @return list<string>
     */
    public function discoverPhpFiles(array $paths, array $excludePatterns = []): array
    {
        $files = [];

        foreach ($paths as $path) {
            if (is_file($path) && str_ends_with(strtolower($path), '.php')) {
                if (! $this->excluded($path, $excludePatterns)) {
                    $files[] = realpath($path) ?: $path;
                }

                continue;
            }

            if (! is_dir($path) || $this->excluded($path, $excludePatterns)) {
                continue;
            }

            $directory = new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS);
            $filter = new RecursiveCallbackFilterIterator(
                $directory,
                fn (SplFileInfo $entry): bool => ! $this->excluded($entry->getPathname(), $excludePatterns),
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
