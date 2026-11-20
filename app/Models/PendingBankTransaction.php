<?php

namespace App\Models;

use App\Enums\Category;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What the bank reported as reserved on an account at the last sync. The
 * bank has given different reserved rows the same id from one sync to the
 * next, and it changes or drops reserved rows without notice. So every sync
 * replaces the account's rows, and nothing here is edited by hand. A
 * reserved row becomes a transaction only when the bank reports it as booked.
 *
 * The amount keeps the bank's sign: negative is money out.
 *
 * @property int $id
 * @property int $user_id
 * @property int $bank_account_id
 * @property string|null $external_id
 * @property Carbon $booked_on
 * @property string $amount
 * @property string $payee
 * @property string|null $note
 * @property string|null $type_text
 * @property string|null $remote_account_number
 * @property Category|null $suggested_category
 * @property bool $counts_as_food
 * @property Carbon $last_seen_at
 */
#[Fillable([
    'user_id',
    'bank_account_id',
    'external_id',
    'booked_on',
    'amount',
    'payee',
    'note',
    'type_text',
    'remote_account_number',
    'suggested_category',
    'counts_as_food',
    'last_seen_at',
])]
class PendingBankTransaction extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'booked_on' => 'date:Y-m-d',
            'amount' => 'decimal:2',
            'suggested_category' => Category::class,
            'counts_as_food' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * Reserved purchases that would count in the food budget if the bank
     * books them as they stand, dated from $from to $to, both included.
     *
     * @param  Builder<PendingBankTransaction>  $query
     */
    public function scopeFoodBetween(Builder $query, string $from, string $to): void
    {
        $query->where('counts_as_food', true)
            ->whereDate('booked_on', '>=', $from)
            ->whereDate('booked_on', '<=', $to);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<BankAccount, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }
}
