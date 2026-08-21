<?php

declare(strict_types=1);

$root = dirname(__DIR__);
foreach ([
    'src/Analysis/Severity.php',
    'src/Analysis/Finding.php',
    'src/Analysis/AnalysisConfig.php',
    'src/Analysis/ClassMetadata.php',
    'src/Analysis/FileContext.php',
    'src/Analysis/ClassMetadataIndex.php',
    'src/Analysis/SourceIndex.php',
    'src/Analysis/SourceScanner.php',
    'src/Analysis/AnalysisResult.php',
    'src/Analysis/Baseline.php',
    'src/TransactionGuard.php',
] as $file) {
    require_once $root.'/'.$file;
}

use Codegenie\TransactionGuard\TransactionGuard;

$directory = sys_get_temp_dir().'/transaction-guard-benchmark-'.bin2hex(random_bytes(4));
mkdir($directory, 0777, true);

try {
    for ($file = 0; $file < 50; $file++) {
        $methods = '';
        for ($method = 0; $method < 20; $method++) {
            $methods .= "    public function run{$method}(): void { DB::transaction(function () { DB::table('orders')->update(['paid' => true]); }); }\n";
        }
        file_put_contents($directory."/Service{$file}.php", "<?php\nnamespace App\\Services;\nuse Illuminate\\Support\\Facades\\DB;\nfinal class Service{$file} {\n{$methods}}\n");
    }

    $startMemory = memory_get_usage(true);
    $start = hrtime(true);
    $result = (new TransactionGuard)->analyze([$directory]);
    $elapsedMs = (hrtime(true) - $start) / 1_000_000;
    $memoryMb = (memory_get_peak_usage(true) - $startMemory) / 1024 / 1024;

    printf("Analyzed %d files in %.2f ms; peak delta %.2f MiB; %d findings.\n", $result->filesAnalyzed, $elapsedMs, $memoryMb, count($result->findings));
} finally {
    foreach (glob($directory.'/*.php') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($directory);
}
