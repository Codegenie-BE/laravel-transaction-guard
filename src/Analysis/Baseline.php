<?php

declare(strict_types=1);

namespace Codegenie\TransactionGuard\Analysis;

final class Baseline
{
    /** @var array<string, int> */
    private array $fingerprintCounts = [];

    /** @param iterable<string> $fingerprints */
    public function __construct(iterable $fingerprints = [])
    {
        foreach ($fingerprints as $fingerprint) {
            $this->fingerprintCounts[$fingerprint] = ($this->fingerprintCounts[$fingerprint] ?? 0) + 1;
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
        if (! is_array($decoded)) {
            throw new \RuntimeException("Baseline [{$path}] must contain a JSON object.");
        }

        $version = $decoded['version'] ?? 1;
        if (! is_int($version) || ! in_array($version, [1, 2], true)) {
            throw new \RuntimeException("Baseline [{$path}] uses an unsupported version.");
        }

        $rawFingerprints = $decoded['fingerprints'] ?? [];
        if (! is_array($rawFingerprints)) {
            throw new \RuntimeException("Baseline [{$path}] must contain a fingerprints array or object.");
        }

        if ($version === 1) {
            $fingerprints = [];
            foreach ($rawFingerprints as $fingerprint) {
                if (! is_string($fingerprint)) {
                    throw new \RuntimeException("Baseline [{$path}] contains an invalid v1 fingerprint.");
                }
                $fingerprints[] = $fingerprint;
            }

            return new self($fingerprints);
        }

        $baseline = new self;
        foreach ($rawFingerprints as $fingerprint => $count) {
            if (! is_string($fingerprint) || ! is_int($count) || $count < 1) {
                throw new \RuntimeException("Baseline [{$path}] contains an invalid v2 fingerprint count.");
            }
            $baseline->fingerprintCounts[$fingerprint] = $count;
        }

        return $baseline;
    }

    /** @param list<Finding> $findings */
    public static function write(string $path, array $findings): void
    {
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new \RuntimeException("Unable to create baseline directory [{$directory}].");
        }

        $fingerprints = [];
        foreach ($findings as $finding) {
            if (RuleCatalog::isDiagnostic($finding->rule)) {
                continue;
            }
            $fingerprint = $finding->fingerprint();
            $fingerprints[$fingerprint] = ($fingerprints[$fingerprint] ?? 0) + 1;
        }
        ksort($fingerprints);

        $payload = [
            'version' => 2,
            'fingerprints' => $fingerprints,
        ];

        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
        if (file_put_contents($path, $encoded) === false) {
            throw new \RuntimeException("Unable to write baseline [{$path}].");
        }
    }

    public function contains(Finding $finding, int $occurrence = 1): bool
    {
        if ($occurrence < 1) {
            throw new \InvalidArgumentException('Baseline occurrence must be at least 1.');
        }

        return ($this->fingerprintCounts[$finding->fingerprint()] ?? 0) >= $occurrence;
    }
}
