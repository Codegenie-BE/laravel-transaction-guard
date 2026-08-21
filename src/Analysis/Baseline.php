<?php

declare(strict_types=1);

namespace Codegenie\TransactionGuard\Analysis;

final class Baseline
{
    /** @var array<string, true> */
    private array $fingerprints = [];

    /** @param iterable<string> $fingerprints */
    public function __construct(iterable $fingerprints = [])
    {
        foreach ($fingerprints as $fingerprint) {
            $this->fingerprints[$fingerprint] = true;
        }
    }

    public static function load(string $path): self
    {
        if (! is_file($path)) {
            return new self;
        }

        $json = file_get_contents($path);
        if ($json === false) {
            throw new \RuntimeException("Unable to read baseline [{$path}].");
        }

        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $fingerprints = is_array($decoded['fingerprints'] ?? null) ? $decoded['fingerprints'] : [];

        return new self(array_values(array_filter($fingerprints, 'is_string')));
    }

    /** @param list<Finding> $findings */
    public static function write(string $path, array $findings): void
    {
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new \RuntimeException("Unable to create baseline directory [{$directory}].");
        }

        $fingerprints = array_values(array_unique(array_map(
            static fn (Finding $finding): string => $finding->fingerprint(),
            $findings,
        )));
        sort($fingerprints);

        $payload = [
            'version' => 1,
            'generated_at' => gmdate(DATE_ATOM),
            'fingerprints' => $fingerprints,
        ];

        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
        if (file_put_contents($path, $encoded) === false) {
            throw new \RuntimeException("Unable to write baseline [{$path}].");
        }
    }

    public function contains(Finding $finding): bool
    {
        return isset($this->fingerprints[$finding->fingerprint()]);
    }
}
