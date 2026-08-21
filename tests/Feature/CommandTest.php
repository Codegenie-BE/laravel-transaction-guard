<?php

declare(strict_types=1);

it('registers the transaction guard command', function (): void {
    $this->artisan('list')
        ->expectsOutputToContain('transaction:guard')
        ->assertSuccessful();
});

it('returns success for a safe file', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'tg-safe-').'.php';
    file_put_contents($file, "<?php\nuse Illuminate\\Support\\Facades\\DB;\nDB::transaction(fn () => DB::table('x')->count());\n");

    try {
        $this->artisan('transaction:guard', ['paths' => [$file]])
            ->assertSuccessful();
    } finally {
        @unlink($file);
    }
});

it('returns failure for a warning-or-higher finding by default', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'tg-unsafe-').'.php';
    file_put_contents($file, "<?php\nuse Illuminate\\Support\\Facades\\DB;\nuse Illuminate\\Support\\Facades\\Http;\nDB::transaction(fn () => Http::post('https://example.test'));\n");

    try {
        $this->artisan('transaction:guard', ['paths' => [$file]])
            ->assertExitCode(1);
    } finally {
        @unlink($file);
    }
});

it('can emit json and ignore findings below a configured threshold', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'tg-json-').'.php';
    file_put_contents($file, "<?php\nuse Illuminate\\Support\\Facades\\DB;\nuse Illuminate\\Support\\Facades\\Storage;\nDB::transaction(fn () => Storage::put('x', 'y'));\n");

    try {
        $this->artisan('transaction:guard', [
            'paths' => [$file],
            '--format' => 'json',
            '--fail-on' => 'error',
        ])->assertSuccessful();
    } finally {
        @unlink($file);
    }
});

it('rejects an invalid output format', function (): void {
    $this->artisan('transaction:guard', ['--format' => 'xml'])
        ->assertExitCode(2);
});

it('rejects an invalid failure threshold', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'tg-threshold-').'.php';
    file_put_contents($file, '<?php');

    try {
        $this->artisan('transaction:guard', [
            'paths' => [$file],
            '--fail-on' => 'fatal',
        ])->assertExitCode(2);
    } finally {
        @unlink($file);
    }
});

it('supports never as a non-failing CI threshold', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'tg-never-').'.php';
    file_put_contents($file, "<?php\nuse Illuminate\\Support\\Facades\\DB;\nuse Illuminate\\Support\\Facades\\Http;\nDB::transaction(fn () => Http::post('https://example.test'));\n");

    try {
        $this->artisan('transaction:guard', [
            'paths' => [$file],
            '--fail-on' => 'never',
        ])->assertSuccessful();
    } finally {
        @unlink($file);
    }
});

it('generates and then applies a baseline', function (): void {
    $dir = sys_get_temp_dir().'/tg-command-baseline-'.bin2hex(random_bytes(4));
    mkdir($dir, 0777, true);
    $file = $dir.'/Unsafe.php';
    $baseline = $dir.'/.transaction-guard-baseline.json';
    file_put_contents($file, "<?php\nuse Illuminate\\Support\\Facades\\DB;\nuse Illuminate\\Support\\Facades\\Http;\nDB::transaction(fn () => Http::post('https://example.test'));\n");

    try {
        $this->artisan('transaction:guard', [
            'paths' => [$file],
            '--baseline' => $baseline,
            '--generate-baseline' => true,
        ])->assertSuccessful();

        expect(is_file($baseline))->toBeTrue();

        $this->artisan('transaction:guard', [
            'paths' => [$file],
            '--baseline' => $baseline,
        ])->assertSuccessful();
    } finally {
        @unlink($baseline);
        @unlink($file);
        @rmdir($dir);
    }
});

it('reports malformed baseline JSON as invalid configuration', function (): void {
    $dir = sys_get_temp_dir().'/tg-command-invalid-baseline-'.bin2hex(random_bytes(4));
    mkdir($dir, 0777, true);
    $file = $dir.'/Safe.php';
    $baseline = $dir.'/baseline.json';
    file_put_contents($file, '<?php');
    file_put_contents($baseline, '{broken');

    try {
        $this->artisan('transaction:guard', [
            'paths' => [$file],
            '--baseline' => $baseline,
        ])->assertExitCode(2);
    } finally {
        @unlink($baseline);
        @unlink($file);
        @rmdir($dir);
    }
});

it('emits GitHub Actions annotations', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'tg-github-').'.php';
    file_put_contents($file, "<?php\nuse Illuminate\\Support\\Facades\\DB;\nuse Illuminate\\Support\\Facades\\Http;\nDB::transaction(fn () => Http::post('https://example.test'));\n");

    try {
        $this->artisan('transaction:guard', [
            'paths' => [$file],
            '--format' => 'github',
            '--fail-on' => 'never',
        ])->expectsOutputToContain('::error file=')
            ->expectsOutputToContain('TG006')
            ->assertSuccessful();
    } finally {
        @unlink($file);
    }
});
