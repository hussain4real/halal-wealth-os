<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Copilot;

enum Tool: string
{
    case Cash = 'cash_availability';
    case Goals = 'goals';
    case Holdings = 'holdings';
    case Valuation = 'valuation_evidence';
    case Assessments = 'shariah_history';

    /** @return array<string,array<string,mixed>> */
    public function properties(): array
    {
        $id = ['type' => 'string', 'pattern' => '^[1-9][0-9]{0,18}$'];
        $cutoff = ['type' => 'string', 'format' => 'date-time'];

        return match ($this) {
            self::Cash => [],
            self::Goals => ['month' => ['type' => 'string', 'pattern' => '^[0-9]{4}-[0-9]{2}$'], 'before' => $id],
            self::Holdings => ['portfolio_id' => $id, 'cutoff' => $cutoff],
            self::Valuation => ['run_id' => $id],
            self::Assessments => ['asset_id' => $id, 'cutoff' => $cutoff, 'before' => $id],
        };
    }

    /** @return list<string> */
    public function required(): array
    {
        return match ($this) {
            self::Cash => [], self::Goals => ['month'], self::Holdings => ['portfolio_id', 'cutoff'],
            self::Valuation => ['run_id'], self::Assessments => ['asset_id', 'cutoff'],
        };
    }

    /** @return list<array<string,mixed>> */
    public static function registry(): array
    {
        return array_map(fn (self $tool): array => ['name' => $tool->value, 'read_only' => true, 'parameters' => [
            'type' => 'object', 'properties' => (object) $tool->properties(), 'required' => $tool->required(), 'additionalProperties' => false,
        ]], self::cases());
    }
}
