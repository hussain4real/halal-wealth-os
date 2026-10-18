<?php

namespace App\Http\Requests\Transactions;

use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class TransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User && $this->user()->hasVerifiedEmail();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $base = ['idempotency_key' => ['required', 'string', 'regex:/^[A-Za-z0-9._:-]{8,120}$/D']];
        if ($this->routeIs('transactions.reverse')) {
            return $base + ['reason' => ['required', 'string', 'max:180']];
        }
        if ($this->routeIs('accounts.post-opening')) {
            return $base;
        }

        return $base + [
            'kind' => ['required', Rule::in(['income', 'expense', 'transfer'])],
            'account_id' => ['required', 'string', 'regex:/^[1-9][0-9]{0,18}$/D'],
            'destination_account_id' => ['nullable', 'string', 'regex:/^[1-9][0-9]{0,18}$/D'],
            'amount' => ['required', 'string', 'max:32'],
            'effective_date' => ['required', 'string', 'date_format:Y-m-d', 'before_or_equal:today'],
            'description' => ['required', 'string', 'max:180'],
            'receipt_reference' => ['nullable', 'string', 'max:180'],
        ];
    }

    /** @return list<Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), [...array_keys($this->rules()), '_token', '_method']) as $field) {
                $validator->errors()->add($field, 'This field cannot be set.');
            }
        }];
    }
}
