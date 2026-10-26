<?php

declare(strict_types=1);

namespace App\Domain\Operations;

enum Channel: string
{
    case Copilot = 'copilot';
    case Market = 'market';
    case Email = 'email';
    case Drive = 'drive';

    public function label(): string
    {
        return match ($this) {
            self::Copilot => 'Copilot admissions', self::Market => 'Market refresh participation',
            self::Email => 'External reminder email', self::Drive => 'Personal Drive actions',
        };
    }

    public function unit(): string
    {
        return match ($this) {
            self::Copilot => 'Reserved token slots, including previews', self::Market => 'Shared provider credits',
            self::Email => 'Email handoff attempts', self::Drive => 'Reserved HTTP calls',
        };
    }

    public function maximumRequests(): int
    {
        return $this === self::Copilot ? 10 : 100;
    }

    public function maximumUnits(): int
    {
        return match ($this) {
            self::Copilot => 65536, self::Drive => 800, default => 100
        };
    }

    public function units(?DriveAction $action = null): int
    {
        return match ($this) {
            self::Copilot => $this->copilotUnits(), self::Drive => ($action ?? throw new \LogicException('Drive action required.'))->calls(), default => 1,
        };
    }

    private function copilotUnits(): int
    {
        $units = config('copilot.tokens_per_request');
        if (! is_int($units) || $units < 1 || $units > $this->maximumUnits()) {
            throw new \LogicException('Copilot reservation configuration is outside the operational ceiling.');
        }

        return $units;
    }
}
