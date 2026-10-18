<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $owner_id
 * @property string $reference
 * @property string $kind
 * @property string $status
 * @property string $payload_hash
 * @property string $description
 * @property string|null $receipt_reference
 * @property int|null $reverses_journal_id
 * @property int|null $replaces_journal_id
 * @property CarbonInterface $effective_date
 * @property CarbonInterface $created_at
 * @property-read Collection<int, Posting> $postings
 */
class JournalEntry extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    /** @return HasMany<Posting, $this> */
    public function postings(): HasMany
    {
        return $this->hasMany(Posting::class, 'journal_id')->orderBy('id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['effective_date' => 'date', 'created_at' => 'immutable_datetime', 'posted_at' => 'immutable_datetime', 'request_payload' => 'array'];
    }
}
