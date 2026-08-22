<?php

declare(strict_types=1);

namespace Codegenie\TransactionGuard\Analysis;

final readonly class FileContext
{
    /** @param array<string, string> $imports */
    public function __construct(
        public string $namespace,
        public array $imports,
    ) {}

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
        if (isset($this->imports[$first])) {
            $suffix = substr($name, strlen($first));

            return $this->imports[$first].$suffix;
        }

        return $this->namespace !== '' ? $this->namespace.'\\'.$name : $name;
    }
}
