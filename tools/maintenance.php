<?php

declare(strict_types=1);

$commands = [
    'composer update --prefer-stable --prefer-dist --with-all-dependencies --no-interaction --no-progress',
    'composer format',
];

foreach ($commands as $command) {
    passthru($command, $status);
    if ($status !== 0) {
        fwrite(STDERR, "Maintenance command failed: {$command}\n");
        exit($status);
    }
}
