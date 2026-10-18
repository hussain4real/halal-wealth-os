<?php

namespace App\Models;

use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property int $id
 * @property int $account_id
 * @property Currency $currency_code
 * @property string $amount_minor
 */
#[Fillable(['currency_code', 'amount_minor'])]
class OpeningBalanceIntent extends Model
{
    protected static function booted(): void
    {
        static::creating(function (OpeningBalanceIntent $intent): void {
            Money::fromMinor($intent->currency_code, $intent->getAttributes()['amount_minor'] ?? null);
        });
        static::updating(function (): never {
            throw new LogicException('An opening request is immutable and remains unposted.');
        });
        static::deleting(function (): never {
            throw new LogicException('Opening requests are retained with their accounts.');
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['currency_code' => Currency::class, 'amount_minor' => 'string'];
    }
}
