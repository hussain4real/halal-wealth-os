<?php

namespace App\Http\Requests;

use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ValuationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User && $this->user()->hasVerifiedEmail();
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        $rules = ['idempotency_key' => ['required', 'string', 'regex:/^[A-Za-z0-9._:-]{8,120}$/D']];
        if ($this->routeIs('prices.store')) {
            return [...$rules, 'context' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'], 'asset_id' => ['required', 'string', 'regex:/^[1-9][0-9]{0,18}$/D'],
                'unit_price' => ['required', 'string', 'max:32'], 'source' => ['required', 'string', 'max:180'], 'reason' => ['required', 'string', 'max:180'],
                'observed_at' => ['required', 'string', 'max:30'], 'effective_at' => ['required', 'string', 'max:30'],
                'supersedes_id' => ['nullable', 'string', 'regex:/^[1-9][0-9]{0,18}$/D'], 'unit_basis_confirmed' => ['required', 'string', 'in:1']];
        }

        return [...$rules, 'base_currency' => ['required', 'string', 'max:3'], 'cutoff' => ['required', 'string', 'max:30'],
            'start' => ['nullable', 'string', 'max:30'], 'account_id' => ['nullable', 'string', 'regex:/^[1-9][0-9]{0,18}$/D']];
    }

    /** @return list<Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, 'This field cannot be submitted.');
            }
        }];
    }
}
