<?php

declare(strict_types=1);

namespace Codegenie\TransactionGuard\Tests\Support;

use Codegenie\TransactionGuard\Analysis\AnalysisConfig;
use Codegenie\TransactionGuard\Analysis\ClassMetadataIndex;
use Codegenie\TransactionGuard\Analysis\Finding;
use Codegenie\TransactionGuard\Analysis\SourceScanner;

final class CatalogScanner
{
    /** @return list<Finding> */
    public static function scan(string $source, ?AnalysisConfig $config = null): array
    {
        $file = tempnam(sys_get_temp_dir(), 'transaction-guard-catalog-');
        if ($file === false) {
            throw new \RuntimeException('Unable to create temporary catalog source file.');
        }

        $phpFile = $file.'.php';
        rename($file, $phpFile);
        file_put_contents($phpFile, $source);

        try {
            $index = ClassMetadataIndex::fromFiles([$phpFile]);

            return (new SourceScanner($index, $config ?? new AnalysisConfig))->scan($phpFile);
        } finally {
            @unlink($phpFile);
        }
    }
}
