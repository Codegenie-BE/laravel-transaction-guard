<?php

declare(strict_types=1);

namespace Codegenie\TransactionGuard\Analysis;

enum Severity: int
{
    case Info = 10;
    case Warning = 20;
    case Error = 30;
    case Critical = 40;

    public static function fromName(string $value): self
    {
        return match (strtolower($value)) {
            'info' => self::Info,
            'warning', 'warn' => self::Warning,
            'error' => self::Error,
            'critical' => self::Critical,
            default => throw new \InvalidArgumentException("Unknown severity [{$value}]."),
        };
    }

    public function label(): string
    {
        return strtolower($this->name);
    }
}
