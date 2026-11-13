<?php

namespace App\Models;

use App\Enums\BookingStatus;
use App\Enums\Category;
use App\Enums\PaymentMethod;
use App\Enums\Subcategory;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A transaction can be split into child rows. The parent stays in the
 * database as the bank row. Budgets, monthly overviews and the transaction
 * list count a split only through its children. A parent returns to the inbox
 * when needs_review is set again, for example after the bank amount changes.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $parent_id
 * @property Carbon $booked_on
 * @property string $amount
 * @property Category|null $category
 * @property Subcategory|null $subcategory
 * @property string $payee
 * @property PaymentMethod|null $payment_method
 * @property string|null $note
 * @property bool $exclude_from_budget
 * @property bool $needs_review
 * @property string|null $external_id
 * @property int|null $bank_account_id
 * @property BookingStatus|null $booking_status
 * @property string|null $dedupe_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'parent_id', 'booked_on', 'amount', 'category', 'subcategory', 'payee', 'payment_method', 'note', 'exclude_from_budget', 'needs_review', 'external_id', 'bank_account_id', 'booking_status', 'dedupe_key'])]
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'booked_on' => 'date',
            'amount' => 'decimal:2',
            'category' => Category::class,
            'subcategory' => Subcategory::class,
            'payment_method' => PaymentMethod::class,
            'exclude_from_budget' => 'boolean',
            'needs_review' => 'boolean',
            'booking_status' => BookingStatus::class,
        ];
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

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Top-level rows that still need a decision. A finished split leaves the
     * inbox, and comes back when the parent is flagged for review.
     *
     * @param  Builder<Transaction>  $query
     */
    public function scopeInbox(Builder $query): void
    {
        $query->whereNull('parent_id')
            ->where(function (Builder $inner): void {
                $inner->where(function (Builder $uncategorized): void {
                    $uncategorized->whereDoesntHave('children')
                        ->whereNull('category')
                        ->where('exclude_from_budget', false);
                })->orWhere('needs_review', true);
            });
    }

    /**
     * Rows that represent spend. A split parent is the bank total; its
     * children are what budgets and lists add up.
     *
     * @param  Builder<Transaction>  $query
     */
    public function scopeExcludingSplitParents(Builder $query): void
    {
        $query->whereDoesntHave('children');
    }
}
