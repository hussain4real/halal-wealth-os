<?php

namespace App\Http\Requests;

use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class GoalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User && $this->user()->hasVerifiedEmail();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $fields = match (true) {
            $this->routeIs('goals.store', 'goals.revise') => ['name', 'account_id', 'currency', 'mode', 'target', 'opening', 'policy', 'contribution', 'standing_order', 'closed'],
            $this->routeIs('goals.reserve') => ['amount', 'reason'],
            $this->routeIs('goals.link') => ['posting_id', 'reason'],
            default => ['reason'],
        };
        $rules = ['idempotency_key' => ['required', 'string', 'regex:/^[A-Za-z0-9._:-]{8,120}$/D']];
        foreach ($fields as $field) {
            $rules[$field] = ['required', 'string', 'max:180'];
        }
        if ($this->routeIs('goals.store', 'goals.revise')) {
            foreach (['first_deposit', 'target_date', 'reason'] as $optional) {
                $rules[$optional] = ['nullable', 'string', 'max:180'];
            }
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
