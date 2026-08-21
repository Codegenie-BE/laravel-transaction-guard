<?php

declare(strict_types=1);

namespace Codegenie\TransactionGuard\Analysis;

final readonly class ClassMetadata
{
    /** @param list<string> $interfaces */
    public function __construct(
        public string $name,
        public array $interfaces,
        public ?string $parent = null,
        public bool $constructorAfterCommit = false,
        public bool $constructorBeforeCommit = false,
        public ?string $constructorQueueConnection = null,
    ) {}

    public function implements(string $interface): bool
    {
        $needle = ltrim($interface, '\\');

        foreach ($this->interfaces as $implemented) {
            if (strcasecmp(ltrim($implemented, '\\'), $needle) === 0 || str_ends_with($implemented, '\\'.$needle)) {
                return true;
            }
        }

        return false;
    }

    public function queued(): bool
    {
        return $this->implements('Illuminate\\Contracts\\Queue\\ShouldQueue')
            || $this->implements('Illuminate\\Contracts\\Queue\\ShouldQueueAfterCommit');
    }

    public function queueAfterCommit(): bool
    {
        return $this->implements('Illuminate\\Contracts\\Queue\\ShouldQueueAfterCommit') || $this->constructorAfterCommit;
    }

    public function eventAfterCommit(): bool
    {
        return $this->implements('Illuminate\\Contracts\\Events\\ShouldDispatchAfterCommit');
    }
}
