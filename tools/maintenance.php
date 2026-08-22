<?php

declare(strict_types=1);

$script = __DIR__.'/finalize_v020.py';

if (! is_file($script)) {
    fwrite(STDERR, "Missing finalization script.\n");
    exit(1);
}

passthru('python3 '.escapeshellarg($script), $status);

if ($status !== 0) {
    exit($status);
}

@unlink($script);
