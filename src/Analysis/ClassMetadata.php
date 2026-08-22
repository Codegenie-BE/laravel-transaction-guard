<?php

declare(strict_types=1);

namespace Codegenie\TransactionGuard\Analysis;

final readonly class ClassMetadata
{
    /**
     * @param  list<string>  $interfaces
     * @param  list<string>  $traits
     */
    public function __construct(
        public string $name,
        public array $interfaces,
        public ?string $parent = null,
        public bool $constructorAfterCommit = false,
        public bool $constructorBeforeCommit = false,
        public ?string $constructorQueueConnection = null,
        public bool $declaresConstructor = false,
        public ?string $queueConnectionAttribute = null,
        public array $traits = [],
        public ?string $queueName = null,
        public ?bool $afterCommitOverride = null,
        public bool $debounced = false,
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

    public function preparesForDispatch(): bool
    {
        return $this->implements('Illuminate\Contracts\Queue\PreparesForDispatch');
    }

    public function uniqueBeforeDispatch(): bool
    {
        return $this->implements('Illuminate\Contracts\Queue\ShouldBeUnique');
    }

    public function usesEventDispatchableTrait(): bool
    {
        foreach ($this->traits as $trait) {
            if (strcasecmp(ltrim($trait, '\\'), 'Illuminate\Foundation\Events\Dispatchable') === 0) {
                return true;
            }
        }

        return false;
    }

    public function explicitlyBeforeCommit(): bool
    {
        if ($this->afterCommitOverride !== null) {
            return $this->afterCommitOverride === false;
        }

        return $this->constructorBeforeCommit;
    }

    public function queueAfterCommit(): bool
    {
        if ($this->afterCommitOverride !== null) {
            return $this->afterCommitOverride;
        }

        if ($this->constructorBeforeCommit) {
            return false;
        }

        return $this->constructorAfterCommit
            || $this->implements('Illuminate\\Contracts\\Queue\\ShouldQueueAfterCommit');
    }

    public function eventAfterCommit(): bool
    {
        return $this->implements('Illuminate\\Contracts\\Events\\ShouldDispatchAfterCommit');
    }
}
