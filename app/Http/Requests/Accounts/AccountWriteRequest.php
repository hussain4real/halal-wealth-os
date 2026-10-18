<?php

namespace App\Http\Requests\Accounts;

use App\Models\Account;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

abstract class AccountWriteRequest extends FormRequest
{
    public function authorize(): bool
    {
        $owner = $this->user();
        if (! $owner instanceof User) {
            return false;
        }
        $id = $this->route('account');
        if ($id !== null) {
            $account = Account::query()->ownedBy($owner)->findOrFail($id);

            return Gate::forUser($owner)->allows('update', $account);
        }

        return Gate::forUser($owner)->allows('create', Account::class);
    }

    /** @return list<string> */
    abstract protected function allowedFields(): array;

    /** @return list<Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), [...$this->allowedFields(), '_token', '_method']) as $field) {
                $validator->errors()->add($field, 'This field cannot be set.');
            }
        }];
    }
}
