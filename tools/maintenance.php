<?php

declare(strict_types=1);

function run(string $command): void
{
    passthru($command, $exitCode);

    if ($exitCode !== 0) {
        throw new RuntimeException("Command failed ({$exitCode}): {$command}");
    }
}

run('composer update --prefer-stable --prefer-dist --with-all-dependencies --no-interaction --no-progress');
run('vendor/bin/pint src/Analysis/SourceScanner.php');

if (is_file('composer.lock')) {
    unlink('composer.lock');
}
