<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Copilot\Answers;

use App\Domain\Ledger\LedgerInput;
use App\Models\User;
use Illuminate\Support\Facades\Validator;

final readonly class AnswerContext
{
    private function __construct(public ?string $goal, public ?string $valuation, public ?string $asset, public string $month, public string $cutoff) {}

    public static function parse(mixed $value): self
    {
        abort_unless(is_array($value) && array_diff(array_keys($value), ['goal_id', 'valuation_id', 'asset_id', 'month', 'cutoff']) === [], 422);
        $v = Validator::make($value, [
            'goal_id' => ['nullable', 'string', 'regex:/^[1-9][0-9]{0,18}$/D'],
            'valuation_id' => ['nullable', 'string', 'regex:/^[1-9][0-9]{0,18}$/D'],
            'asset_id' => ['nullable', 'string', 'regex:/^[1-9][0-9]{0,18}$/D'],
            'month' => ['required', 'date_format:Y-m'], 'cutoff' => ['required', 'date_format:Y-m-d\TH:i:s\Z'],
        ])->validate();
        $cutoff = LedgerInput::instant($v['cutoff'], 'cutoff');
        abort_if($cutoff > now('UTC')->format('Y-m-d\TH:i:s\Z'), 422);

        return new self($v['goal_id'] ?? null, $v['valuation_id'] ?? null, $v['asset_id'] ?? null, $v['month'], $cutoff);
    }

    public function authorize(User $owner): void
    {
        foreach (['goal_plans' => $this->goal, 'investment_valuation_runs' => $this->valuation, 'portfolio_assets' => $this->asset] as $table => $id) {
            if ($id !== null) {
                LedgerInput::owned($table, $owner, $id);
            }
        }
    }
}
