<?php

namespace App\Http\Requests;

use App\Domain\Reporting\DashboardRules;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class DashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User && $this->user()->hasVerifiedEmail();
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        $rules = ['idempotency_key' => ['required', 'string', 'regex:/^[A-Za-z0-9._:-]{8,120}$/D']];
        if ($this->routeIs('dashboard.configure')) {
            $rules += ['settings_id' => ['present', 'nullable', 'string', 'max:19'], 'grace_days' => ['required', 'string', 'max:3'],
                'targets' => ['required', 'string', 'max:4000'], 'reason' => ['required', 'string', 'max:180']];
            foreach (DashboardRules::RULES as $key => $label) {
                $rules[$key] = ['required', 'string', 'in:0,1'];
            }
        } elseif ($this->routeIs('dashboard.dismiss')) {
            $rules += ['snapshot_id' => ['present', 'nullable', 'string', 'max:19'], 'base_currency' => ['required', 'string', 'max:3'],
                'action_key' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'], 'reason' => ['required', 'string', 'max:180']];
        } else {
            $rules += ['base_currency' => ['required', 'string', 'max:3'], 'settings_id' => ['present', 'nullable', 'string', 'max:19']];
        }

        return $rules;
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
