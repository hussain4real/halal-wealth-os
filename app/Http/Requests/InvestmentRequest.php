<?php

namespace App\Http\Requests;

use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class InvestmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User && $this->user()->hasVerifiedEmail();
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        $rules = ['idempotency_key' => ['required', 'string', 'regex:/^[A-Za-z0-9._:-]{8,120}$/D'], 'reason' => ['required', 'string', 'max:180']];
        if ($this->routeIs('investment.reverse')) {
            return $rules;
        }
        $rules += ['kind' => ['required', 'string'], 'asset_id' => ['required', 'string', 'regex:/^[1-9][0-9]{0,18}$/D'],
            'effective_date' => ['required', 'string', 'max:10'], 'amount' => ['required', 'string', 'max:32'],
            'action_reference' => ['required', 'string', 'max:180'], 'source' => ['required', 'string', 'max:180']];
        foreach (['quantity', 'unit_price', 'fee', 'tax', 'split_numerator', 'split_denominator', 'subscription_id', 'confirmed', 'replaces_id'] as $field) {
            $rules[$field] = ['nullable', 'string', 'max:32'];
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
