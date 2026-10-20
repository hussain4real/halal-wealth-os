<?php

namespace App\Http\Requests;

use App\Domain\Shariah\AssessmentService;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User && $this->user()->hasVerifiedEmail();
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return ['idempotency_key' => ['required', 'string', 'regex:/^[A-Za-z0-9._:-]{8,120}$/D'],
            'context' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'], 'supersedes_id' => ['nullable', 'string', 'regex:/^[1-9][0-9]{0,18}$/D'],
            'provenance' => ['required', 'string'], 'provider' => ['required', 'string', 'max:180'], 'methodology_version' => ['nullable', 'string', 'max:180'],
            'classification' => ['required', 'string'], 'effective_on' => ['required', 'string', 'max:10'], 'review_due_on' => ['nullable', 'string', 'max:10'],
            'evidence_refs' => ['present', 'array', 'list', 'max:12'], 'evidence_refs.*' => ['required', 'string', 'max:1000'], 'source_url' => ['nullable', 'string', 'max:2000'],
            'business_findings' => ['nullable', 'string', 'max:4000'], 'financial_findings' => ['nullable', 'string', 'max:4000'], 'notes' => ['nullable', 'string', 'max:4000'],
            'reason' => ['required', 'string', 'max:180']];
    }

    /** @return list<Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['idempotency_key', ...AssessmentService::FIELDS]) as $field) {
                $validator->errors()->add($field, 'This field cannot be submitted.');
            }
        }];
    }
}
