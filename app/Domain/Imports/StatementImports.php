<?php

declare(strict_types=1);

namespace App\Domain\Imports;

use App\Domain\Ledger\LedgerInput;
use App\Domain\Ledger\PostingService;
use App\Domain\Portfolio\InvestmentReplay;
use App\Domain\Portfolio\InvestmentService;
use App\Domain\Portfolio\PortfolioInput;
use App\Domain\Portfolio\PortfolioRead;
use App\Domain\Shared\Money\Money;
use App\Models\Account;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Owns import evidence and orchestration; existing domain services alone write finance.
 * @phpstan-type ImportCommand array{type:'ledger',currency:string,input:array<string,mixed>}|array{type:'investment',currency:string,input:array<string,mixed>,portfolio_id:string}|array{type:'match',currency:string,target:array<string,mixed>}
 */
final class StatementImports
{
    public function __construct(private CsvStatement $csv, private PostingService $posting, private InvestmentService $investments) {}

    /** @param array<string,mixed> $mapping
     * @param array<mixed> $resolutions */
    public function preview(User $owner, string $bytes, string $namespace, string $accountId, string $adapterName, array $mapping, array $resolutions = []): stdClass
    {
        $namespace = LedgerInput::text($namespace, 'namespace');
        $adapterName = LedgerInput::text($adapterName, 'adapter_name');
        $mapping = $this->csv->mapping($mapping);

        return DB::transaction(function () use ($owner, $bytes, $namespace, $accountId, $adapterName, $mapping, $resolutions): stdClass {
            LedgerInput::lock($owner);
            $account = $this->account($owner, $accountId);
            $rows = $this->csv->rows($bytes, $mapping, $account->currency_code, $resolutions);
            $digest = hash('sha256', $bytes);
            $source = DB::table('statement_sources')->where('owner_id', $owner->id)->where('namespace', $namespace)->where('account_id', $accountId)->where('digest', $digest)->first();
            if ($source === null) {
                if ((int) DB::table('statement_sources')->where('owner_id', $owner->id)->sum('byte_count') + strlen($bytes) > 16 * 1024 * 1024) {
                    LedgerInput::fail('file', 'Retained statement sources reached the16MiB owner limit. Export history and review retention before adding sources.');
                }
                $source = $this->insert('sources', $owner, ['namespace' => $namespace, 'account_id' => $accountId, 'currency' => $account->currency_code->value,
                    'digest' => $digest, 'byte_count' => strlen($bytes), 'source_base64' => base64_encode($bytes)]);
            }
            $adapterDigest = LedgerInput::hash($mapping);
            $adapter = DB::table('statement_adapters')->where('owner_id', $owner->id)->where('digest', $adapterDigest)->first()
                ?? $this->insert('adapters', $owner, ['name' => $adapterName, 'config' => $this->json($mapping), 'digest' => $adapterDigest]);
            foreach ($rows as &$row) {
                $row['command'] = null;
                if ($row['data'] !== null) {
                    try {
                        $row['command'] = $this->compile($owner, $account, $row['data']);
                    } catch (ValidationException $error) {
                        $row['errors'] = array_values(array_map(fn (array $messages): string => $messages[0], $error->errors()));
                    } catch (NotFoundHttpException|ModelNotFoundException) {
                        $row['errors'] = ['An explicit reference is unavailable to this owner.'];
                    }
                }
            }
            unset($row);
            $interpretation = ['version' => 1, 'resolutions' => $resolutions, 'rows' => $rows];
            $snapshotDigest = LedgerInput::hash(['adapter' => $adapterDigest, 'interpretation' => $interpretation]);

            return DB::table('statement_snapshots')->where('owner_id', $owner->id)->where('source_id', $source->id)->where('digest', $snapshotDigest)->first()
                ?? $this->insert('snapshots', $owner, ['source_id' => $source->id, 'adapter_id' => $adapter->id, 'digest' => $snapshotDigest, 'interpretation' => $this->json($interpretation)]);
        });
    }

    /** @param array<mixed> $resolutions */
    public function revise(User $owner, string $id, array $resolutions): stdClass
    {
        $snapshot = LedgerInput::owned('statement_snapshots', $owner, $id);
        $source = LedgerInput::owned('statement_sources', $owner, (string) $snapshot->source_id);
        $adapter = LedgerInput::owned('statement_adapters', $owner, (string) $snapshot->adapter_id);
        $bytes = base64_decode($source->source_base64, true);
        if ($bytes === false || hash('sha256', $bytes) !== $source->digest) {
            LedgerInput::fail('source', 'Retained source integrity did not reconcile.');
        }

        return $this->preview($owner, $bytes, $source->namespace, (string) $source->account_id, $adapter->name, json_decode($adapter->config, true, flags: JSON_THROW_ON_ERROR), $resolutions);
    }

    /** @return array<string,mixed> */
    public function show(User $owner, string $id): array
    {
        return PortfolioRead::coherent(function () use ($owner, $id): array {
            $snapshot = LedgerInput::owned('statement_snapshots', $owner, $id);
            $source = LedgerInput::owned('statement_sources', $owner, (string) $snapshot->source_id);
            $adapter = LedgerInput::owned('statement_adapters', $owner, (string) $snapshot->adapter_id);
            $rows = $this->rows($snapshot);
            $keys = [];
            $modes = [];
            $candidateFacts = [];
            foreach ($rows as $row) {
                if ($row['data'] !== null) {
                    $modes[$this->fact($row)][$row['data']['provider_id'] === '' ? 'fingerprint' : 'provider'] = true;
                }
                if ($row['errors'] === []) {
                    foreach ($this->keys($source, $row) as $logical => $hash) {
                        $candidateFacts[$logical][$hash] = true;
                    }
                }
                if ($row['data'] !== null && $row['data']['provider_id'] === '' && $row['data']['occurrence'] === '') {
                    $fingerprint = $this->fact($row);
                    $keys[$fingerprint] = ($keys[$fingerprint] ?? 0) + 1;
                }
            }
            foreach ($rows as &$row) {
                $row['base_errors'] = $row['errors'];
                $row['existing'] = null;
                $row['resolution_status'] = $row['errors'] === [] ? 'ready' : 'invalid';
                if ($row['errors'] === []) {
                    $collision = false;
                    foreach ($this->keys($source, $row) as $logical => $hash) {
                        $collision = $collision || count($candidateFacts[$logical]) > 1;
                    }
                    if ($collision) {
                        $row['resolution_status'] = 'ambiguous';
                        $row['errors'][] = 'Rows in this preview reuse one provider or transfer identity with different financial facts. Resolve the source conflict before selecting any of them.';
                        $row['ready'] = false;

                        continue;
                    }
                    $existing = null;
                    $identityErrors = [];
                    try {
                        $existing = $this->existing($owner, $this->keys($source, $row), $this->transferHash($row));
                    } catch (ValidationException $error) {
                        $identityErrors = array_values(array_map(fn (array $messages): string => $messages[0], $error->errors()));
                    }
                    $repeated = $row['data']['provider_id'] === '' && $row['data']['occurrence'] === '' && ($keys[$this->fact($row)] ?? 0) > 1;
                    $anchored = $existing !== null && in_array($this->identity(['source', (string) $source->id, $row['number']]), $existing['logical_keys'], true);
                    if ($existing !== null && (! $repeated || $anchored)) {
                        $row['existing'] = ['receipt_id' => $existing['receipt_id'], 'token' => $existing['token'], 'type' => $existing['outcome']['type']];
                        $row['resolution_status'] = 'retained';
                    } elseif (count($modes[$this->fact($row)] ?? []) > 1 || $this->crossMode($owner, $source, $row)) {
                        $row['resolution_status'] = 'ambiguous';
                        $row['errors'][] = 'An equivalent retained row uses another provider-ID/fingerprint mode. Retain the original identity or resolve it explicitly; no second entry will be guessed.';
                    } elseif ($repeated) {
                        $row['resolution_status'] = 'ambiguous';
                        $row['errors'][] = 'Indistinguishable repeated rows need persistent distinct occurrence labels or explicit provider IDs. Nothing will be guessed.';
                    } elseif ($identityErrors !== []) {
                        $row['resolution_status'] = 'identity_conflict';
                        $row['errors'] = $identityErrors;
                    }
                }
                $row['ready'] = $row['errors'] === [] && $row['existing'] === null;
            }
            unset($row);

            return ['id' => (string) $snapshot->id, 'reference' => $snapshot->reference, 'source_reference' => $source->reference, 'source_digest' => $source->digest,
                'namespace' => $source->namespace, 'account_id' => (string) $source->account_id, 'currency' => $source->currency,
                'mapping' => json_decode($adapter->config, true, flags: JSON_THROW_ON_ERROR), 'resolutions' => json_decode($snapshot->interpretation, true, flags: JSON_THROW_ON_ERROR)['resolutions'], 'rows' => $rows,
                'receipts' => DB::table('statement_receipts')->where('owner_id', $owner->id)->where('snapshot_id', $id)->orderByDesc('id')->get(['id', 'reference'])->map(fn (stdClass $r): array => ['id' => (string) $r->id, 'reference' => $r->reference])->all()];
        });
    }

    private function atomic(Closure $work): stdClass
    {
        return DB::transaction(function () use ($work): stdClass {
            try {
                return $work();
            } catch (ValidationException $error) {
                throw new ImportRejected($error->errors());
            }
        });
    }

    /** @param array<mixed> $selection */
    public function approve(User $owner, string $id, string $key, array $selection): stdClass
    {
        return $this->atomic(function () use ($owner, $id, $key, $selection): stdClass {
            $selection = $this->selection($selection);
            $payload = ['snapshot_id' => $id, 'selection' => $selection];
            LedgerInput::lock($owner);
            if ($cached = LedgerInput::cached('statement_receipts', $owner, $key, $payload)) {
                return $cached;
            }
            $view = $this->show($owner, $id);
            $snapshot = LedgerInput::owned('statement_snapshots', $owner, $id);
            $source = LedgerInput::owned('statement_sources', $owner, (string) $snapshot->source_id);
            $rows = array_values(array_filter($view['rows'], fn (array $r): bool => in_array($r['number'], $selection, true)));
            if (count($rows) !== count($selection)) {
                LedgerInput::fail('selection', 'Choose only retained rows in this preview.');
            }
            usort($rows, fn (array $a, array $b): int => [$a['data']['effective_date'] ?? '', (int) $a['number']] <=> [$b['data']['effective_date'] ?? '', (int) $b['number']]);
            $outcomes = [];
            $claims = [];
            $pending = [];
            foreach ($rows as $row) {
                if ($row['errors'] !== []) {
                    LedgerInput::fail('selection', 'Row'.$row['number'].' is unresolved. Review its preview reason; no selected rows were committed.');
                }
                $keys = $this->keys($source, $row);
                $existing = $this->existing($owner, $keys, $this->transferHash($row));
                $token = $row['number'];
                $within = null;
                foreach ($keys as $logical => $hash) {
                    if (isset($pending[$logical])) {
                        if ($pending[$logical]['hash'] !== $hash) {
                            LedgerInput::fail('selection', 'Selected rows reuse one identity with different facts. Nothing was committed.');
                        }
                        $within = $pending[$logical]['token'];
                    }
                }
                if ($existing !== null) {
                    $outcome = ['type' => 'duplicate', 'target' => $existing['outcome']['target'], 'origin' => ['receipt_id' => $existing['receipt_id'], 'token' => $existing['token']]];
                } elseif ($within !== null) {
                    $outcome = ['type' => 'duplicate', 'target' => $outcomes[$within]['target'], 'origin' => ['receipt_id' => null, 'token' => $within]];
                } else {
                    $command = $this->compile($owner, $this->account($owner, (string) $source->account_id), $row['data']);
                    $target = $this->execute($owner, $command, 'statement:'.hash('sha256', $key.':'.$token));
                    $outcome = ['type' => $command['type'] === 'match' ? 'matched' : 'created', 'target' => $target, 'origin' => null];
                }
                $outcomes[$token] = [...$outcome, 'row' => $token, 'fact_hash' => $this->fact($row), 'transfer_hash' => $this->transferHash($row), 'data' => $row['data'], 'command' => $row['command']];
                foreach ($keys as $logical => $hash) {
                    if (! isset($pending[$logical])) {
                        $pending[$logical] = ['hash' => $hash, 'token' => $token];
                        if (array_key_exists($logical, $this->keys($source, $row, $existing === null))) {
                            $claims[$logical] = ['hash' => $hash, 'token' => $token, 'existing' => $existing];
                        }
                    }
                }
            }
            $receipt = $this->insert('receipts', $owner, ['idempotency_key' => LedgerInput::key($key), 'payload_hash' => LedgerInput::hash($payload), 'request_payload' => $this->json($payload),
                'snapshot_id' => $id, 'outcomes' => $this->json($outcomes)]);
            foreach ($claims as $logical => $claim) {
                $outcome = $outcomes[$claim['token']];
                $origin = $claim['existing'] ?? ['receipt_id' => (string) $receipt->id, 'token' => $outcome['origin']['token'] ?? $claim['token']];
                $this->claim($owner, $logical, $claim['hash'], (string) $origin['receipt_id'], $origin['token'], $outcome['target']);
            }

            return $receipt;
        });
    }

    /** @return array<string,mixed> */
    public function receipt(User $owner, string $id): array
    {
        $receipt = LedgerInput::owned('statement_receipts', $owner, $id);
        $outcomes = json_decode($receipt->outcomes, true, flags: JSON_THROW_ON_ERROR);
        foreach ($outcomes as $token => &$outcome) {
            $outcome['token'] = (string) $token;
            $next = $this->successor($owner, $id, (string) $token);
            $outcome['correction_receipt_id'] = $next === null ? null : (string) $next->id;
            $outcome['correctable'] = in_array($outcome['type'], ['created', 'replacement'], true) && $next === null;
        }
        unset($outcome);

        return ['id' => $id, 'reference' => $receipt->reference, 'snapshot_id' => (string) $receipt->snapshot_id, 'reason' => $receipt->reason,
            'corrects_receipt_id' => $receipt->corrects_receipt_id === null ? null : (string) $receipt->corrects_receipt_id, 'outcomes' => array_values($outcomes)];
    }

    public function correct(User $owner, string $receiptId, string $token, string $key, string $reason, ?string $replacementId = null, ?string $replacementRow = null): stdClass
    {
        return $this->atomic(function () use ($owner, $receiptId, $token, $key, $reason, $replacementId, $replacementRow): stdClass {
            $reason = LedgerInput::text($reason, 'reason');
            $payload = ['receipt_id' => $receiptId, 'token' => $token, 'reason' => $reason, 'replacement_id' => $replacementId, 'replacement_row' => $replacementRow];
            LedgerInput::lock($owner);
            if ($cached = LedgerInput::cached('statement_receipts', $owner, $key, $payload)) {
                return $cached;
            }
            $original = LedgerInput::owned('statement_receipts', $owner, $receiptId);
            $outcomes = json_decode($original->outcomes, true, flags: JSON_THROW_ON_ERROR);
            $prior = $outcomes[$token] ?? null;
            abort_unless(is_array($prior), 404);
            if (! in_array($prior['type'], ['created', 'replacement'], true) || $this->successor($owner, $receiptId, $token) !== null) {
                LedgerInput::fail('correction', 'Only the latest import-created outcome can be corrected. Matches grant no reversal authority.');
            }
            $target = $prior['target'];
            $row = null;
            $source = null;
            $snapshotId = (string) $original->snapshot_id;
            if ($replacementId !== null) {
                $view = $this->show($owner, $replacementId);
                foreach ($view['rows'] as $candidate) {
                    if ($candidate['number'] === $replacementRow) {
                        $row = $candidate;
                        break;
                    }
                }
                if ($row === null || $row['base_errors'] !== [] || $row['resolution_status'] === 'ambiguous' || $row['command']['type'] === 'match') {
                    LedgerInput::fail('replacement', 'Choose an unresolved-free new replacement row from an owned preview.');
                }
                $command = $row['command'];
                if ($command['type'] !== $prior['command']['type'] || $command['input']['kind'] !== $prior['command']['input']['kind']) {
                    LedgerInput::fail('replacement', 'A replacement retains the original financial operation kind.');
                }
                $snapshotId = $replacementId;
                $snapshot = LedgerInput::owned('statement_snapshots', $owner, $snapshotId);
                $source = LedgerInput::owned('statement_sources', $owner, (string) $snapshot->source_id);
                foreach (DB::table('statement_claims')->where('owner_id', $owner->id)->whereIn('logical_key', array_keys($this->keys($source, $row)))->get() as $claim) {
                    $claimed = $this->follow($owner, (string) $claim->receipt_id, $claim->token);
                    if ($claimed['receipt_id'] !== $receiptId || $claimed['token'] !== $token) {
                        LedgerInput::fail('replacement', 'The replacement source or provider identity belongs to a different retained operation.');
                    }
                }
                $command = $this->compile($owner, $this->account($owner, (string) $source->account_id), $row['data']);
                if ($command['type'] === 'match' || $command['type'] !== $prior['command']['type']) {
                    LedgerInput::fail('replacement', 'A replacement retains the original financial operation kind.');
                }
                if ($command['type'] === 'investment') {
                    if ($command['portfolio_id'] !== $target['portfolio_id'] || $command['input']['action_reference'] !== $prior['command']['input']['action_reference']) {
                        LedgerInput::fail('replacement', 'Investment corrections retain their portfolio and business action reference.');
                    }
                    $event = $this->investments->record($owner, $target['portfolio_id'], 'statement:'.hash('sha256', $key), [...$command['input'], 'replaces_id' => $target['event_id']]);
                    $result = $this->eventTarget($event);
                } else {
                    $journal = $this->posting->correct($owner, $target['journal_id'], 'statement:'.hash('sha256', $key), $command['input']);
                    $result = $this->journalTarget($journal->id, $command['currency']);
                }
            } elseif ($replacementRow !== null) {
                LedgerInput::fail('replacement', 'A replacement row requires its owned preview.');
            } elseif ($target['event_id'] !== null) {
                $event = $this->investments->reverse($owner, $target['portfolio_id'], $target['event_id'], 'statement:'.hash('sha256', $key), $reason);
                $result = $this->eventTarget($event);
            } else {
                $journal = $this->posting->reverse($owner, $target['journal_id'], 'statement:'.hash('sha256', $key), $reason);
                $result = $this->journalTarget($journal->id, $target['currency']);
            }
            $outcome = ['type' => $row === null ? 'cancelled' : 'replacement', 'target' => $result, 'original_target' => $target,
                'origin' => ['receipt_id' => $receiptId, 'token' => $token], 'row' => $row['number'] ?? $token,
                'fact_hash' => $row === null ? $prior['fact_hash'] : $this->fact($row), 'transfer_hash' => $row === null ? $prior['transfer_hash'] : $this->transferHash($row), 'data' => $row['data'] ?? $prior['data'], 'command' => $row['command'] ?? $prior['command']];
            $receipt = $this->insert('receipts', $owner, ['idempotency_key' => LedgerInput::key($key), 'payload_hash' => LedgerInput::hash($payload), 'request_payload' => $this->json($payload),
                'snapshot_id' => $snapshotId, 'corrects_receipt_id' => $receiptId, 'corrects_token' => $token, 'reason' => $reason, 'outcomes' => $this->json(['1' => $outcome])]);
            if ($row !== null) {
                foreach ($this->keys($source, $row, $row['resolution_status'] !== 'retained') as $logical => $hash) {
                    $this->claim($owner, $logical, $hash, (string) $receipt->id, '1', $result);
                }
            }

            return $receipt;
        });
    }

    /** @param array<mixed> $selection
     * @return list<string> */
    private function selection(array $selection): array
    {
        if (! array_is_list($selection) || $selection === [] || count($selection) > 1000 || count(array_unique($selection, SORT_REGULAR)) !== count($selection)) {
            LedgerInput::fail('selection', 'Explicitly select between1 and1000 unique rows.');
        }
        foreach ($selection as $number) {
            if (! is_string($number) || ! preg_match('/^[1-9][0-9]{0,3}$/D', $number)) {
                LedgerInput::fail('selection', 'Selected row identities must be retained row numbers.');
            }
        }
        usort($selection, fn (string $a, string $b): int => (int) $a <=> (int) $b);

        return $selection;
    }

    private function account(User $owner, string $id): Account
    {
        LedgerInput::identifier($id);
        $account = Account::query()->ownedBy($owner)->whereKey($id)->firstOrFail();
        if ($account->system_role !== null || ! $account->type->contributesToNetWorth()) {
            LedgerInput::fail('account_id', 'Choose a personal financial account.');
        }

        return $account;
    }

    /** @param array<string,mixed> $d
     * @return ImportCommand */
    private function compile(User $owner, Account $account, array $d): array
    {
        $kind = $d['kind'];
        $amount = Money::fromMinor($account->currency_code, $d['amount_minor'])->amount();
        $currency = $account->currency_code->value;
        if (in_array($kind, ['income', 'expense', 'transfer_in', 'transfer_out'], true)) {
            $this->optionalCash($d, in_array($kind, ['income', 'transfer_in'], true) ? $d['amount_minor'] : bcsub('0', $d['amount_minor'], 0));
            $input = ['kind' => str_starts_with($kind, 'transfer_') ? 'transfer' : $kind, 'account_id' => (string) $account->id, 'destination_account_id' => '',
                'amount' => $amount, 'effective_date' => $d['effective_date'], 'description' => $d['description'], 'receipt_reference' => $d['operation_reference'] ?: null];
            if (str_starts_with($kind, 'transfer_')) {
                $other = $this->account($owner, LedgerInput::identifier($d['counterparty_account_id']));
                if ($other->id === $account->id || $other->currency_code !== $account->currency_code || $d['operation_reference'] === '' || $d['match_id'] !== '') {
                    LedgerInput::fail('transfer', 'An explicit transfer needs two different same-currency accounts and one stable transfer reference.');
                }
                $input['account_id'] = (string) ($kind === 'transfer_out' ? $account->id : $other->id);
                $input['destination_account_id'] = (string) ($kind === 'transfer_out' ? $other->id : $account->id);
            } elseif ($d['counterparty_account_id'] !== '' || $d['match_id'] !== '') {
                LedgerInput::fail('reference', 'A cash entry cannot silently consume transfer or investment references.');
            }
            if ($d['asset_id'] !== '' || $d['quantity'] !== '' || $d['unit_price'] !== '' || $d['fee_minor'] !== '0' || $d['tax_minor'] !== '0') {
                LedgerInput::fail('type', 'Investment details require an explicit investment fact type.');
            }

            return ['type' => 'ledger', 'currency' => $currency, 'input' => $input];
        }
        if (in_array($kind, ['match_transfer', 'match_investment'], true)) {
            if ($d['quantity'] !== '' || $d['unit_price'] !== '' || $d['fee_minor'] !== '0' || $d['tax_minor'] !== '0') {
                LedgerInput::fail('match', 'Cash-leg matches do not accept trade units, prices, fees or tax. Use a complete recorded investment fact instead.');
            }
            if (! in_array($d['direction'], ['in', 'out'], true)) {
                LedgerInput::fail('direction', 'A match requires explicit in/out cash direction.');
            }
            $signed = $d['direction'] === 'in' ? $d['amount_minor'] : bcsub('0', $d['amount_minor'], 0);
            $this->optionalCash($d, $signed);
            if ($kind === 'match_transfer') {
                $journal = LedgerInput::owned('journal_entries', $owner, LedgerInput::identifier($d['match_id']));
                if ($journal->kind !== 'transfer' || $journal->status !== 'posted' || DB::table('investment_event_journals')->where('journal_id', $journal->id)->exists()
                    || DB::table('fx_operation_journals')->where('journal_id', $journal->id)->exists()) {
                    LedgerInput::fail('match', 'Choose an active ordinary transfer root, never an investment or FX cash member.');
                }
                $date = $journal->effective_date;
                $journalId = $journal->id;
                $other = DB::table('postings as p')->join('accounts as a', 'a.id', '=', 'p.account_id')->where('p.owner_id', $owner->id)->where('p.journal_id', $journalId)->where('p.account_id', '<>', $account->id)->whereNull('a.system_role')->value('p.account_id');
                if (($d['counterparty_account_id'] !== '' && $d['counterparty_account_id'] !== (string) $other) || $d['asset_id'] !== ''
                    || ($d['operation_reference'] !== '' && $d['operation_reference'] !== $journal->receipt_reference)) {
                    LedgerInput::fail('match', 'The supplied transfer counterparty/reference must agree exactly; investment assets do not belong to a transfer match.');
                }
                $target = $this->journalTarget($journalId, $currency);
            } else {
                $event = LedgerInput::owned('investment_events', $owner, LedgerInput::identifier($d['match_id']));
                if ($d['counterparty_account_id'] !== '' || ($d['operation_reference'] !== '' && $d['operation_reference'] !== $event->action_reference)) {
                    LedgerInput::fail('match', 'Investment cash matches do not accept a transfer counterparty or a conflicting trade reference.');
                }
                if ($event->kind === 'reversal' || DB::table('investment_events')->where('reverses_id', $event->id)->exists()) {
                    LedgerInput::fail('match', 'Choose an active complete investment event.');
                }
                $portfolio = LedgerInput::owned('portfolio_accounts', $owner, (string) $event->portfolio_account_id);
                if ((string) $portfolio->account_id !== (string) $account->id || $event->currency !== $currency
                    || ($d['asset_id'] !== '' && (string) $event->asset_id !== $d['asset_id'])) {
                    LedgerInput::fail('match', 'The event must belong to this investment cash account and supplied asset.');
                }
                $journalId = DB::table('investment_event_journals')->where('owner_id', $owner->id)->where('event_id', $event->id)->value('journal_id');
                $date = $event->effective_date;
                $target = $this->eventTarget($event);
            }
            $net = DB::table('postings')->where('owner_id', $owner->id)->where('journal_id', $journalId)->where('account_id', $account->id)->selectRaw('sum(amount_minor)::text AS amount')->value('amount');
            if ($date !== $d['effective_date'] || $net !== $signed) {
                LedgerInput::fail('match', 'The exact account, date, currency, direction and net cash must match the selected root.');
            }

            return ['type' => 'match', 'currency' => $currency, 'target' => $target];
        }
        $portfolio = DB::table('portfolio_accounts')->where('owner_id', $owner->id)->where('account_id', $account->id)->first();
        if ($d['counterparty_account_id'] !== '' || $d['match_id'] !== '') {
            LedgerInput::fail('investment', 'New investment facts cannot silently consume transfer or existing-match references.');
        }
        if ($portfolio === null || $d['asset_id'] === '' || $d['operation_reference'] === '') {
            LedgerInput::fail('investment', 'Investment facts need an owned linked investment account, explicit asset and stable business action reference.');
        }
        $asset = LedgerInput::owned('portfolio_assets', $owner, $d['asset_id']);
        if ($asset->currency !== $currency || ! in_array($asset->kind, ['equity', 'unitized_fund'], true)) {
            LedgerInput::fail('asset_id', 'Choose an owned unitized asset in this account currency.');
        }
        if (in_array($kind, ['buy', 'sell'], true)) {
            PortfolioInput::decimal($d['quantity'], 'quantity', true);
            if ($d['unit_price'] !== '') {
                PortfolioInput::decimal($d['unit_price'], 'unit_price');
            }
        } elseif ($d['quantity'] !== '' || $d['unit_price'] !== '') {
            LedgerInput::fail('quantity', 'Only buy/sell rows record units or execution prices in CSV-v1.');
        }
        if (($kind !== 'dividend' && $d['tax_minor'] !== '0') || ($kind === 'fee' && $d['fee_minor'] !== '0')
            || (in_array($kind, ['sell', 'dividend'], true) && bccomp(bcadd($d['fee_minor'], $d['tax_minor'], 0), $d['amount_minor'], 0) > 0)) {
            LedgerInput::fail('fee', 'Gross consideration, fees and dividend tax must describe a valid net cash effect.');
        }
        $input = ['kind' => $kind, 'asset_id' => $d['asset_id'], 'effective_date' => $d['effective_date'], 'quantity' => $d['quantity'], 'unit_price' => $d['unit_price'],
            'amount' => $amount, 'fee' => Money::fromMinor($account->currency_code, $d['fee_minor'])->amount(), 'tax' => Money::fromMinor($account->currency_code, $d['tax_minor'])->amount(),
            'action_reference' => $d['operation_reference'], 'source' => 'CSV statement evidence', 'reason' => $d['description']];
        $cash = InvestmentReplay::cash((object) ['kind' => $kind, 'amount_minor' => $d['amount_minor'], 'fee_minor' => $d['fee_minor'], 'tax_minor' => $d['tax_minor']]);
        try {
            Money::fromMinor($account->currency_code, bcadd($d['amount_minor'], $d['fee_minor'], 0));
        } catch (InvalidArgumentException) {
            LedgerInput::fail('amount', 'Total consideration plus fees exceeds the exact native money range.');
        }
        $this->optionalCash($d, $cash);

        return ['type' => 'investment', 'currency' => $currency, 'portfolio_id' => (string) $portfolio->id, 'input' => $input];
    }

    /** @param ImportCommand $command
     * @return array<string,mixed> */
    private function execute(User $owner, array $command, string $key): array
    {
        return match ($command['type']) {
            'ledger' => $this->journalTarget($this->posting->record($owner, $key, $command['input'])->id, $command['currency']),
            'investment' => $this->eventTarget($this->investments->record($owner, $command['portfolio_id'], $key, $command['input'])),
            'match' => $command['target'],
        };
    }

    /** @param array<string,mixed> $row */
    private function fact(array $row): string
    {
        $data = $row['data'];
        unset($data['provider_id'], $data['occurrence'], $data['cash_minor'], $data['direction']);

        return LedgerInput::hash($data);
    }

    /** @param array<string,mixed> $row
     * @return array<string,string> */
    private function keys(stdClass $source, array $row, bool $retainFingerprint = true): array
    {
        $fact = $this->fact($row);
        $data = $row['data'];
        $primary = $data['provider_id'] === '' ? ['fingerprint', $fact, $data['occurrence']] : ['provider', $data['provider_id']];
        $keys = [$this->identity(['source', (string) $source->id, $row['number']]) => $fact];
        if ($retainFingerprint || $data['provider_id'] !== '') {
            $keys[$this->identity([$source->namespace, (string) $source->account_id, ...$primary])] = $fact;
        }
        if (in_array($data['kind'], ['transfer_out', 'transfer_in'], true)) {
            $keys[$this->identity(['transfer', $data['operation_reference']])] = $this->transferHash($row);
        }

        return $keys;
    }

    /** @param array<string,string> $keys
     * @return array<string,mixed>|null */
    private function existing(User $owner, array $keys, ?string $transferHash = null): ?array
    {
        $found = null;
        $claims = DB::table('statement_claims')->where('owner_id', $owner->id)->whereIn('logical_key', array_keys($keys))->get();
        $hasTransferIdentity = $transferHash !== null && $claims->contains(fn (stdClass $claim): bool => $keys[$claim->logical_key] === $transferHash);
        foreach ($claims as $claim) {
            $history = $this->follow($owner, (string) $claim->receipt_id, $claim->token);
            if ($claim->fact_hash !== $keys[$claim->logical_key] && ! in_array($keys[$claim->logical_key], $history['fact_hashes'], true)
                && (! $hasTransferIdentity || ! in_array($transferHash, $history['transfer_hashes'], true))) {
                LedgerInput::fail('identity', 'This source row or stable identity already has different facts. Open its original receipt and use a linked correction.');
            }
            if ($found !== null && $found['receipt_id'].':'.$found['token'] !== $history['receipt_id'].':'.$history['token']) {
                LedgerInput::fail('identity', 'Explicit identities refer to different retained outcomes. Resolve the conflict before approval.');
            }
            $found = $history;
        }

        return $found === null ? null : [...$found, 'logical_keys' => $claims->pluck('logical_key')->all()];
    }

    /** @return array<string,mixed> */
    private function follow(User $owner, string $id, string $token): array
    {
        $hashes = [];
        $transferHashes = [];
        for ($depth = 0; $depth < 100; $depth++) {
            $receipt = LedgerInput::owned('statement_receipts', $owner, $id);
            $outcomes = json_decode($receipt->outcomes, true, flags: JSON_THROW_ON_ERROR);
            $outcome = $outcomes[$token];
            $hashes[] = $outcome['fact_hash'];
            if ($outcome['transfer_hash'] !== null) {
                $transferHashes[] = $outcome['transfer_hash'];
            }
            $next = $this->successor($owner, $id, $token);
            if ($next === null) {
                return ['receipt_id' => $id, 'token' => $token, 'outcome' => $outcome, 'fact_hashes' => $hashes, 'transfer_hashes' => $transferHashes];
            }
            $id = (string) $next->id;
            $token = '1';
        }
        LedgerInput::fail('history', 'Correction history exceeds the bounded view. Review retained receipts before continuing.');
    }

    private function successor(User $owner, string $id, string $token): ?stdClass
    {
        return DB::table('statement_receipts')->where('owner_id', $owner->id)->where('corrects_receipt_id', $id)->where('corrects_token', $token)->first();
    }

    /** @param array<string,mixed> $data
     * @param numeric-string $cash */
    private function optionalCash(array $data, string $cash): void
    {
        $direction = bccomp($cash, '0', 0) === 0 ? '' : (str_starts_with($cash, '-') ? 'out' : 'in');
        if (($data['cash_minor'] !== null && $data['cash_minor'] !== $cash) || ($data['direction'] !== '' && $data['direction'] !== $direction)) {
            LedgerInput::fail('cash_amount', 'Supplied signed cash and direction must agree with the complete operation and its fees/tax.');
        }
    }

    /** @param array<string,mixed> $row */
    private function transferHash(array $row): ?string
    {
        if (! in_array($row['data']['kind'], ['transfer_out', 'transfer_in'], true) || $row['command'] === null) {
            return null;
        }
        $input = $row['command']['input'];
        unset($input['description'], $input['receipt_reference']);

        return LedgerInput::hash($input);
    }

    /** Cross-mode equality is ambiguity, never permission to collapse distinct provider IDs.
     * @param array<string,mixed> $row */
    private function crossMode(User $owner, stdClass $source, array $row): bool
    {
        $hasProvider = $row['data']['provider_id'] !== '';
        foreach (DB::table('statement_claims as c')->join('statement_receipts as r', 'r.id', '=', 'c.receipt_id')->join('statement_snapshots as p', 'p.id', '=', 'r.snapshot_id')->join('statement_sources as s', 's.id', '=', 'p.source_id')
            ->where('c.owner_id', $owner->id)->where('s.namespace', $source->namespace)->where('s.account_id', $source->account_id)->where('c.fact_hash', $this->fact($row))->get(['c.token', 'r.outcomes']) as $claim) {
            $outcomes = json_decode($claim->outcomes, true, flags: JSON_THROW_ON_ERROR);
            if (($outcomes[$claim->token]['data']['provider_id'] !== '') !== $hasProvider) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $target */
    private function claim(User $owner, string $logical, string $hash, string $receiptId, string $token, array $target): void
    {
        if (DB::table('statement_claims')->where('owner_id', $owner->id)->where('logical_key', $logical)->exists()) {
            return;
        }
        DB::table('statement_claims')->insert(['owner_id' => $owner->id, 'logical_key' => $logical, 'fact_hash' => $hash, 'receipt_id' => $receiptId, 'token' => $token, ...$target]);
    }

    /** @return array<string,mixed> */
    private function journalTarget(int $id, string $currency): array
    {
        return ['journal_id' => (string) $id, 'event_id' => null, 'portfolio_id' => null, 'currency' => $currency];
    }

    /** @return array<string,mixed> */
    private function eventTarget(stdClass $event): array
    {
        return ['journal_id' => null, 'event_id' => (string) $event->id, 'portfolio_id' => (string) $event->portfolio_account_id, 'currency' => $event->currency];
    }

    /** @return list<array<string,mixed>> */
    private function rows(stdClass $snapshot): array
    {
        $interpretation = json_decode($snapshot->interpretation, true, flags: JSON_THROW_ON_ERROR);
        if ($interpretation['version'] !== 1) {
            LedgerInput::fail('version', 'This retained interpretation requires a supported parser version.');
        }

        return $interpretation['rows'];
    }

    /** @param array<string,mixed> $data */
    private function insert(string $table, User $owner, array $data): stdClass
    {
        $id = DB::table('statement_'.$table)->insertGetId(['owner_id' => $owner->id, 'reference' => (string) Str::ulid(), ...$data]);

        return LedgerInput::owned('statement_'.$table, $owner, (string) $id);
    }

    /** @param list<mixed> $parts */
    private function identity(array $parts): string
    {
        return hash('sha256', $this->json($parts));
    }

    /** @param array<mixed> $data */
    private function json(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
