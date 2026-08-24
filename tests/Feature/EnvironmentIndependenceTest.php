<?php

declare(strict_types=1);

use Codegenie\TransactionGuard\Analysis\AnalysisConfig;
use Codegenie\TransactionGuard\Analysis\DatabaseDriverPolicy;
use Codegenie\TransactionGuard\Analysis\Severity;

it('does not access environment variables directly from package runtime or config', function (): void {
    $root = dirname(__DIR__, 2);
    $directories = [$root.'/src', $root.'/config'];
    $forbiddenFunctions = ['env' => true, 'getenv' => true, 'putenv' => true];
    $forbiddenVariables = ['$_ENV' => true, '$_SERVER' => true];
    $violations = [];

    foreach ($directories as $directory) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            if (! is_string($source)) {
                $violations[] = $file->getPathname().': unreadable source';

                continue;
            }

            $tokens = token_get_all($source, TOKEN_PARSE);
            $tokenCount = count($tokens);

            foreach ($tokens as $index => $token) {
                if (! is_array($token)) {
                    continue;
                }

                [$tokenId, $text, $line] = $token;

                if ($tokenId === T_VARIABLE && isset($forbiddenVariables[$text])) {
                    $violations[] = $file->getPathname().':'.$line.' uses '.$text;

                    continue;
                }

                $function = strtolower($text);
                if ($tokenId !== T_STRING || ! isset($forbiddenFunctions[$function])) {
                    continue;
                }

                $next = null;
                for ($cursor = $index + 1; $cursor < $tokenCount; $cursor++) {
                    $candidate = $tokens[$cursor];
                    if (is_array($candidate) && in_array($candidate[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }
                    $next = $candidate;
                    break;
                }

                if ($next !== '(') {
                    continue;
                }

                $previous = null;
                for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
                    $candidate = $tokens[$cursor];
                    if (is_array($candidate) && in_array($candidate[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }
                    $previous = $candidate;
                    break;
                }

                if (is_array($previous) && in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)) {
                    continue;
                }

                $violations[] = $file->getPathname().':'.$line.' calls '.$function.'()';
            }
        }
    }

    expect($violations)->toBe([]);
});

it('runs when host queue and database configuration are absent', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'tg-no-env-').'.php';
    file_put_contents($file, "<?php\nuse Illuminate\\Support\\Facades\\DB;\nuse Illuminate\\Support\\Facades\\Http;\nDB::transaction(fn () => Http::post('https://example.test'));\n");

    $config = $this->app['config'];
    $originalQueue = $config->get('queue');
    $originalDatabase = $config->get('database');

    try {
        $config->set('queue', null);
        $config->set('database', null);

        $this->artisan('transaction:guard', [
            'paths' => [$file],
            '--format' => 'json',
            '--fail-on' => 'never',
        ])->expectsOutputToContain('TG006')
            ->assertSuccessful();
    } finally {
        $config->set('queue', $originalQueue);
        $config->set('database', $originalDatabase);
        @unlink($file);
    }
});

it('uses conservative analysis defaults without host configuration', function (): void {
    $config = new AnalysisConfig;
    $ddl = DatabaseDriverPolicy::ddl($config->databaseDriver());

    expect($config->defaultQueueConnection)->toBe('sync')
        ->and($config->queueDispatchesAfterCommit())->toBeFalse()
        ->and($config->databaseDriver())->toBeNull()
        ->and($ddl['severity'])->toBe(Severity::Critical)
        ->and($ddl['semantics'])->toBe('unknown');
});
