<?php

namespace App\Models;

use App\Domain\Ledger\AccountType;
use App\Domain\Shared\Money\Currency;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * @property int $id
 * @property int $owner_id
 * @property string $name
 * @property AccountType $type
 * @property Currency $currency_code
 * @property CarbonInterface|null $archived_at
 * @property string|null $system_role
 * @property-read OpeningBalanceIntent|null $openingBalanceIntent
 */
#[Fillable(['name', 'type', 'currency_code'])]
class Account extends Model
{
    /** @param array<string, mixed> $options */
    public function save(array $options = []): bool
    {
        if (! $this->exists) {
            return parent::save($options);
        }

        return $this->getConnection()->transaction(function () use ($options): bool {
            $current = static::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();
            if ($current->archived_at !== null) {
                throw new LogicException('Archived accounts cannot be changed, including through stale models.');
            }

            return parent::save($options);
        });
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return HasOne<OpeningBalanceIntent, $this> */
    public function openingBalanceIntent(): HasOne
    {
        return $this->hasOne(OpeningBalanceIntent::class);
    }

    /** @param Builder<static> $query */
    public function scopeOwnedBy(Builder $query, User $owner): void
    {
        $query->where('owner_id', $owner->id);
    }

    protected static function booted(): void
    {
        static::updating(function (Account $account): void {
            if ($account->getOriginal('archived_at') !== null || $account->isDirty(['owner_id', 'currency_code', 'type', 'system_role']) || $account->system_role !== null) {
                throw new LogicException('Archived accounts and account ownership/currency/type are immutable.');
            }
        });
        static::deleting(function (): never {
            throw new LogicException('Accounts must be archived, never deleted.');
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['type' => AccountType::class, 'currency_code' => Currency::class, 'archived_at' => 'datetime'];
    }
}
