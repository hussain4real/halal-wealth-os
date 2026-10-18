<?php

namespace App\Http\Requests;

use App\Domain\Planning\PlanningInput;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class BudgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User && $this->user()->hasVerifiedEmail();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $fields = match (true) {
            $this->routeIs('budgets.categories.store', 'budgets.categories.revise') => ['name', 'direction', 'normal_budget', 'emergency_essential'],
            $this->routeIs('budgets.schedules.store', 'budgets.schedules.revise') => ['name', 'category_id', 'currency', 'amount', 'cadence', 'first_due_date', 'salary', 'standing_order'],
            $this->routeIs('budgets.attribute') => ['journal_id', 'category_id', 'reason'],
            $this->routeIs('budgets.allocate') => ['schedule_id', 'due_date', 'journal_id', 'amount'],
            default => ['reason'],
        };
        $rules = ['idempotency_key' => ['required', 'string', 'regex:/^[A-Za-z0-9._:-]{8,120}$/D'], 'month' => ['nullable', 'string', 'regex:/^[0-9]{4}-[0-9]{2}$/D']];
        foreach ($fields as $field) {
            $rules[$field] = ['required', 'string', 'max:180'];
        }
        if ($this->routeIs('budgets.schedules.store', 'budgets.schedules.revise')) {
            foreach (['interval_months', 'provision_start_date', 'end_date', 'cutover_date', 'reason'] as $optional) {
                $rules[$optional] = ['nullable', 'string', 'max:180'];
            }
        }
        if ($this->routeIs('budgets.categories.store', 'budgets.categories.revise')) {
            $rules['reason'] = ['nullable', 'string', 'max:180'];
        }

        return $rules;
    }

    /** @return list<Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->filled('month')) {
                PlanningInput::month($this->input('month'));
            }
            foreach (array_diff(array_keys($this->all()), [...array_keys($this->rules()), '_token', '_method']) as $field) {
                $validator->errors()->add($field, 'This field cannot be submitted.');
            }
        }];
    }
}
