<?php

declare(strict_types=1);

use Codegenie\TransactionGuard\Analysis\AnalysisConfig;

it('fails parse diagnostics even with fail-on never', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'tg-parse-').'.php';
    file_put_contents($file, '<?php function broken( {');
    try {
        $this->artisan('transaction:guard', ['paths' => [$file], '--fail-on' => 'never'])
            ->assertExitCode(1);
    } finally {
        @unlink($file);
    }
});

it('does not generate a baseline while diagnostics exist', function (): void {
    $dir = sys_get_temp_dir().'/tg-diagnostic-baseline-'.bin2hex(random_bytes(4));
    mkdir($dir, 0777, true);
    $file = $dir.'/Broken.php';
    $baseline = $dir.'/baseline.json';
    file_put_contents($file, '<?php function broken( {');
    try {
        $this->artisan('transaction:guard', ['paths' => [$file], '--baseline' => $baseline, '--generate-baseline' => true])
            ->assertExitCode(1);
        expect(is_file($baseline))->toBeFalse();
    } finally {
        @unlink($file);
        @unlink($baseline);
        @rmdir($dir);
    }
});

it('rejects missing scan paths', function (): void {
    $this->artisan('transaction:guard', ['paths' => [sys_get_temp_dir().'/does-not-exist-'.bin2hex(random_bytes(4))]])
        ->assertExitCode(2);
});

it('rejects unknown disabled rule ids', function (): void {
    expect(fn () => new AnalysisConfig(disabledRules: ['TG0006']))
        ->toThrow(InvalidArgumentException::class);
});

it('explains a canonical rule', function (): void {
    $this->artisan('transaction:guard', ['--explain' => 'TG006'])
        ->expectsOutputToContain('TG006')
        ->assertSuccessful();
});

it('can fail CI on unresolved transaction callbacks', function (): void {
    $dir = sys_get_temp_dir().'/tg-unresolved-'.bin2hex(random_bytes(4));
    mkdir($dir, 0777, true);
    $file = $dir.'/Service.php';
    file_put_contents($file, "<?php use Illuminate\Support\Facades\DB; DB::transaction([new stdClass, 'run']);");
    try {
        config()->set('transaction-guard.fail_on_unresolved_transaction', true);
        $this->artisan('transaction:guard', ['paths' => [$file], '--fail-on' => 'never'])->assertExitCode(1);
    } finally {
        @unlink($file);
        @rmdir($dir);
    }
});

it('accepts alternate PCRE delimiters for custom patterns', function (): void {
    expect(fn () => new AnalysisConfig(customSideEffectPatterns: ['~SmsGateway::send\s*\(~i']))
        ->not->toThrow(InvalidArgumentException::class);
});

it('resolves an eloquent parent through the composer loader', function (): void {
    $dir = sys_get_temp_dir().'/tg-vendor-parent-'.bin2hex(random_bytes(4));
    mkdir($dir, 0777, true);
    $file = $dir.'/User.php';
    file_put_contents($file, <<<'PHP'
<?php
namespace App\Models;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
class User extends Authenticatable { protected $connection = 'pgsql'; }
DB::connection('mysql')->transaction(function () { User::create(['name' => 'A']); });
PHP);
    try {
        config()->set('database.default', 'mysql');
        $this->artisan('transaction:guard', ['paths' => [$file], '--format' => 'json'])
            ->expectsOutputToContain('TG021')
            ->assertExitCode(1);
    } finally {
        @unlink($file);
        @rmdir($dir);
    }
});
