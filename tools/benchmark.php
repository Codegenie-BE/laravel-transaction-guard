<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap-analysis.php';

use Codegenie\TransactionGuard\TransactionGuard;

/** @return array{files:int,ms:float,memory:float,findings:int} */
function benchmarkTransactionGuard(string $name, int $files, callable $sourceFactory): array
{
    $directory = sys_get_temp_dir().'/transaction-guard-benchmark-'.$name.'-'.bin2hex(random_bytes(4));
    mkdir($directory, 0777, true);
    try {
        for ($file = 0; $file < $files; $file++) {
            file_put_contents($directory."/Service{$file}.php", $sourceFactory($file));
        }
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }
        $startMemory = memory_get_usage(true);
        $start = hrtime(true);
        $result = (new TransactionGuard)->analyze([$directory]);

        return ['files' => $result->filesAnalyzed, 'ms' => (hrtime(true) - $start) / 1_000_000,
            'memory' => max(0, memory_get_peak_usage(true) - $startMemory) / 1024 / 1024,
            'findings' => count($result->findings)];
    } finally {
        foreach (glob($directory.'/*.php') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    }
}

$jsonOutput = in_array('--json', $argv, true);
$workloads = [
    'transaction-free-1000' => [1000, static fn (int $file): string => "<?php\nnamespace App\\Services; final class Service{$file} { public function run(): int { return 1; } }\n"],
    'safe-transactions-250' => [250, static fn (int $file): string => "<?php\nnamespace App\\Services; use Illuminate\\Support\\Facades\\DB; final class Service{$file} { public function run(): void { DB::transaction(fn () => DB::table('orders')->update(['paid' => true])); } }\n"],
    'side-effect-heavy-250' => [250, static fn (int $file): string => "<?php\nnamespace App\\Services; use Illuminate\\Support\\Facades\\DB; use Illuminate\\Support\\Facades\\Http; final class Service{$file} { public function run(): void { DB::transaction(fn () => Http::post('https://example.test')); } }\n"],
    'metadata-heavy-250' => [250, static fn (int $file): string => "<?php\nnamespace App\\Jobs; use Illuminate\\Contracts\\Queue\\ShouldQueue; class Base{$file} implements ShouldQueue {} class Service{$file} extends Base{$file} {}\n"],
    'mixed-laravel-100' => [100, static fn (int $file): string => "<?php\nnamespace App\\Services; use Illuminate\\Support\\Facades\\{DB,Http,Cache,Redis}; final class Service{$file} { public function run(): void { DB::transaction(function () { Http::post('https://example.test'); Cache::put('k', 1); Redis::set('k', 'v'); DB::table('orders')->update(['paid'=>true]); }); } }\n"],
];
$results = [];
foreach ($workloads as $name => [$files, $factory]) {
    $result = benchmarkTransactionGuard($name, $files, $factory);
    $results[$name] = $result;
    if (! $jsonOutput) {
        printf("%s: %d files in %.2f ms; peak delta %.2f MiB; %d findings.\n", $name, $result['files'], $result['ms'], $result['memory'], $result['findings']);
    }
}
if ($jsonOutput) {
    echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
}
