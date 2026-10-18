<?php

namespace App\Http\Requests\Accounts;

class UpdateAccountRequest extends AccountWriteRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:120']];
    }

    /** @return list<string> */
    protected function allowedFields(): array
    {
        return ['name'];
    }
}
