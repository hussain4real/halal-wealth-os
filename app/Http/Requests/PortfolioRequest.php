<?php

namespace App\Http\Requests;

use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PortfolioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User && $this->user()->hasVerifiedEmail();
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        $key = ['idempotency_key' => ['required', 'string', 'regex:/^[A-Za-z0-9._:-]{8,120}$/D']];
        $text = ['required', 'string', 'max:180'];
        $optional = ['nullable', 'string', 'max:180'];
        $id = ['required', 'string', 'regex:/^[1-9][0-9]{0,18}$/D'];
        if ($this->routeIs('assets.store')) {
            return [...$key, 'prior_id' => ['nullable', 'string', 'regex:/^[1-9][0-9]{0,18}$/D'], 'name' => $text,
                'kind' => ['required', 'string', 'in:equity,unitized_fund,manual_investment'], 'currency' => ['required', 'string', 'max:3'],
                'geography' => $optional, 'geography_source' => $optional, 'identifier' => $optional, 'market' => $optional, 'source' => $text, 'unsupported_terms' => $optional, 'reason' => $text];
        }
        if ($this->routeIs('portfolio.store')) {
            return [...$key, 'account_id' => $id, 'platform_label' => $text, 'source' => $text];
        }
        if ($this->routeIs('portfolio.opening.save')) {
            return [...$key, 'prior_id' => ['nullable', 'string', 'regex:/^[1-9][0-9]{0,18}$/D'], 'as_of' => ['required', 'string', 'max:10'], 'source' => $text, 'reason' => $text,
                'rows' => ['present', 'array', 'max:1000'], 'rows.*' => ['array:asset_id,quantity,unit_price,manual_value,basis_known,cost_basis,acquired_on'],
                'rows.*.acquired_on' => ['nullable', 'string', 'max:10'], 'rows.*.asset_id' => $id, 'rows.*.quantity' => ['nullable', 'string', 'max:32'], 'rows.*.unit_price' => ['nullable', 'string', 'max:32'],
                'rows.*.manual_value' => ['nullable', 'string', 'max:32'], 'rows.*.basis_known' => ['required', 'string', 'in:0,1'], 'rows.*.cost_basis' => ['nullable', 'string', 'max:32']];
        }
        if ($this->routeIs('portfolio.preview')) {
            return [...$key, 'mode' => ['required', 'string', 'in:detailed,aggregate'], 'platform_label' => $text, 'source' => $text, 'reason' => $text,
                'aggregate_value' => ['nullable', 'string', 'max:32'], 'aggregate_as_of' => ['nullable', 'string', 'max:10']];
        }

        return [...$key, 'preview_id' => $id, 'acknowledge' => ['required', 'string', 'in:1']];
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
