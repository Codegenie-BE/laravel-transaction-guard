<?php

declare(strict_types=1);

namespace Codegenie\TransactionGuard\Analysis;

final readonly class FileContext
{
    /** @var array<string, string> */
    private array $normalizedImports;

    /** @param array<string, string> $imports */
    public function __construct(
        public string $namespace,
        public array $imports,
    ) {
        $normalized = [];
        foreach ($imports as $alias => $fqcn) {
            $normalized[strtolower($alias)] = $fqcn;
        }
        $this->normalizedImports = $normalized;
    }

    public function resolve(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }

        if ($name[0] === '\\') {
            return ltrim($name, '\\');
        }

        if (str_starts_with(strtolower($name), 'namespace\\')) {
            $relative = substr($name, strlen('namespace\\'));

            return $this->namespace !== '' ? $this->namespace.'\\'.$relative : $relative;
        }

        [$first] = explode('\\', $name, 2);
        $import = $this->normalizedImports[strtolower($first)] ?? null;
        if ($import !== null) {
            $suffix = substr($name, strlen($first));

            return $import.$suffix;
        }

        return $this->namespace !== '' ? $this->namespace.'\\'.$name : $name;
    }
}
