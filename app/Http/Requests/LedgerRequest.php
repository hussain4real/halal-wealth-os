<?php

namespace App\Http\Requests;

use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class LedgerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User && $this->user()->hasVerifiedEmail();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $fields = match (true) {
            $this->routeIs('fx.store', 'fx.correct') => ['source_account_id', 'destination_account_id', 'source_amount', 'destination_amount', 'fee_amount', 'fee_side', 'rate_value', 'rate_source', 'observed_at', 'effective_date', 'description'],
            $this->routeIs('fx.reverse') => ['reason'],
            $this->routeIs('rates.store') => ['base_currency', 'quote_currency', 'value', 'source', 'observed_at', 'effective_at'],
            $this->routeIs('reports.store') => ['base_currency', 'cutoff'],
            $this->routeIs('reconciliations.store') => ['account_id', 'statement_amount', 'cutoff'],
            $this->routeIs('reconciliations.adjust') => ['offset', 'reason'],
            default => [],
        };
        $rules = ['idempotency_key' => ['required', 'string', 'regex:/^[A-Za-z0-9._:-]{8,120}$/D']];
        foreach ($fields as $field) {
            $rules[$field] = ['required', 'string', 'max:180'];
        }
        if ($this->routeIs('rates.store', 'reconciliations.store')) {
            $rules['supersedes_id'] = ['nullable', 'string', 'regex:/^[1-9][0-9]{0,18}$/D'];
        }
        if ($this->routeIs('rates.store')) {
            $rules['correction_reason'] = ['nullable', 'string', 'max:180'];
        }

        return $rules;
    }

    /** @return list<Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), [...array_keys($this->rules()), '_token', '_method']) as $field) {
                $validator->errors()->add($field, 'This field cannot be submitted.');
            }
        }];
    }
}
