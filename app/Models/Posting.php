<?php

namespace App\Models;

use App\Domain\Shared\Money\Currency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $journal_id
 * @property int $owner_id
 * @property int $account_id
 * @property Currency $currency_code
 * @property numeric-string $amount_minor
 * @property-read Account $account
 */
class Posting extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['currency_code' => Currency::class, 'amount_minor' => 'string'];
    }
}
