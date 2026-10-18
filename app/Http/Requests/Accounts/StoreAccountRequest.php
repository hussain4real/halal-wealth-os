<?php

namespace App\Http\Requests\Accounts;

use App\Domain\Ledger\AccountType;
use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use Closure;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class StoreAccountRequest extends AccountWriteRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::enum(AccountType::class)],
            'currency_code' => ['required', Rule::enum(Currency::class)],
            'opening_balance' => ['nullable', 'string', 'max:40', function (string $attribute, mixed $value, Closure $fail): void {
                $currency = is_string($this->input('currency_code')) ? Currency::tryFrom($this->input('currency_code')) : null;
                if ($currency !== null) {
                    try {
                        Money::fromDecimal($currency, $value);
                    } catch (InvalidArgumentException $error) {
                        $fail($error->getMessage());
                    }
                }
            }],
        ];
    }

    /** @return list<string> */
    protected function allowedFields(): array
    {
        return ['name', 'type', 'currency_code', 'opening_balance'];
    }
}
