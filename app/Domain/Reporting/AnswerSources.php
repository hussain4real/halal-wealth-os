<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Ledger\LedgerInput;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class AnswerSources
{
    private const TABLES = ['cash_settings' => 'cash_availability_settings', 'account' => 'accounts', 'goal' => 'goal_plans', 'valuation' => 'investment_valuation_runs', 'asset' => 'portfolio_assets', 'assessment' => 'shariah_assessments'];

    /** @return array<string,list<array{id:string,label:string}>> */
    public function choices(User $owner): array
    {
        $goals = DB::table('goal_plans as g')->where('g.owner_id', $owner->id)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('goal_plans as n')->whereColumn('n.supersedes_id', 'g.id'))->orderByDesc('g.id')->limit(100)->get(['g.id', 'g.name', 'g.version']);
        $assets = DB::table('portfolio_assets as a')->where('a.owner_id', $owner->id)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('portfolio_assets as n')->whereColumn('n.supersedes_id', 'a.id'))->orderByDesc('a.id')->limit(100)->get(['a.id', 'a.name', 'a.version']);
        $valuations = DB::table('investment_valuation_runs')->where('owner_id', $owner->id)->orderByDesc('id')->limit(100)->selectRaw("id,reference,evidence->>'cutoff' AS cutoff,evidence->>'base_currency' AS currency")->get();

        return ['goals' => array_values($goals->map(fn ($r): array => ['id' => (string) $r->id, 'label' => $r->name.' · v'.$r->version])->all()),
            'assets' => array_values($assets->map(fn ($r): array => ['id' => (string) $r->id, 'label' => $r->name.' · v'.$r->version])->all()),
            'valuations' => array_values($valuations->map(fn ($r): array => ['id' => (string) $r->id, 'label' => $r->reference.' · '.$r->currency.' · '.$r->cutoff])->all())];
    }

    /** @return array<string,mixed> */
    public function resolve(User $owner, string $kind, string $id): array
    {
        abort_unless(isset(self::TABLES[$kind]), 404);
        $row = LedgerInput::owned(self::TABLES[$kind], $owner, $id);
        $record = array_intersect_key((array) $row, array_flip(['id', 'reference', 'version', 'series_id', 'asset_id', 'classification', 'provider', 'methodology_version', 'effective_on', 'review_due_on', 'recorded_at', 'created_at']));

        return ['kind' => $kind, 'record_id' => (string) $row->id, 'record' => $record,
            'scope' => 'Authorized retained source record. The answer has its own capture/cutoff; this source view is not a recreated historical answer.'];
    }

    /** @return array{kind:string,record_id:string,label:string,href:string} */
    public function citation(User $owner, string $kind, string $id, string $label): array
    {
        $this->resolve($owner, $kind, $id);

        return ['kind' => $kind, 'record_id' => $id, 'label' => $label, 'href' => '/copilot/source/'.$kind.'/'.$id];
    }
}
