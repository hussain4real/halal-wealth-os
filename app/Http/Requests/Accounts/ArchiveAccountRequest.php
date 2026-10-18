<?php

namespace App\Http\Requests\Accounts;

class ArchiveAccountRequest extends AccountWriteRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }

    /** @return list<string> */
    protected function allowedFields(): array
    {
        return [];
    }
}
