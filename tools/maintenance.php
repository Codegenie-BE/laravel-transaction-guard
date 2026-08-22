<?php

declare(strict_types=1);

$script = __DIR__.'/finalize_v020.py';

if (! is_file($script)) {
    fwrite(STDERR, "Missing finalization script.\n");
    exit(1);
}

$python = file_get_contents($script);
if ($python === false) {
    fwrite(STDERR, "Unable to read finalization script.\n");
    exit(1);
}

$old = <<<'PY'
    if position < 0:
        raise SystemExit(f"final array marker not found in {path}")
    write(path, text[:position] + "\n" + addition.rstrip() + "\n" + text[position:])
PY;
$new = <<<'PY'
    if position < 0:
        write(path, text.rstrip() + "\n\n" + addition.rstrip() + "\n")
        return
    write(path, text[:position] + "\n" + addition.rstrip() + "\n" + text[position:])
PY;

if (! str_contains($python, $old)) {
    fwrite(STDERR, "Finalizer helper patch target not found.\n");
    exit(1);
}

file_put_contents($script, str_replace($old, $new, $python));

passthru('python3 '.escapeshellarg($script), $status);

if ($status !== 0) {
    exit($status);
}

@unlink($script);
