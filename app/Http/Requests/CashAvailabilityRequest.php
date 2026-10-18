<?php

namespace App\Http\Requests;

use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CashAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User && $this->user()->hasVerifiedEmail();
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        if ($this->routeIs('cash.scenarios.calculate')) {
            return ['fingerprint' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'], 'overrides' => ['present', 'array:salary,contribution,investment_target,commitments'],
                'overrides.*' => ['required', 'string', 'max:32']];
        }

        return ['idempotency_key' => ['required', 'string', 'regex:/^[A-Za-z0-9._:-]{8,120}$/D'],
            'prior_id' => ['nullable', 'string', 'max:19'], 'currency' => ['required', 'string', 'max:3'],
            'buffer' => ['required', 'string', 'max:32'], 'next_income_date' => ['required', 'string', 'max:10'],
            'investment_target' => ['required', 'string', 'max:32'], 'reason' => ['required', 'string', 'max:180'],
            'requirements' => ['present', 'array', 'max:1000'], 'requirements.*' => ['array:schedule_id,required'],
            'requirements.*.schedule_id' => ['required', 'string', 'max:19'], 'requirements.*.required' => ['required', 'string', 'in:0,1'],
            'investment_goals' => ['present', 'array', 'max:1000'], 'investment_goals.*' => ['required', 'string', 'max:19'],
            'overlaps' => ['present', 'array', 'max:1000'], 'overlaps.*' => ['array:reservation_id,schedule_id,due_date,amount'],
            'overlaps.*.reservation_id' => ['required', 'string', 'max:19'], 'overlaps.*.schedule_id' => ['required', 'string', 'max:19'],
            'overlaps.*.due_date' => ['required', 'string', 'max:10'], 'overlaps.*.amount' => ['required', 'string', 'max:32']];
    }

    /** @return list<Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $allowed = array_unique(array_map(fn (string $key): string => explode('.', $key)[0], array_keys($this->rules())));
            foreach (array_diff(array_keys($this->all()), [...$allowed, '_token', '_method']) as $field) {
                $validator->errors()->add($field, 'This field cannot be submitted.');
            }
        }];
    }
}
