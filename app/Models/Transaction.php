<?php

namespace App\Models;

use App\Enums\Category;
use App\Enums\PaymentMethod;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A transaction can be split into two child rows. The parent stays in the
 * database (uncategorized) as an audit trail of the original imported amount.
 * Inbox excludes parents that have children — they are "categorized away" by
 * the split rather than deleted, which keeps foreign keys and history intact.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $parent_id
 * @property Carbon $booked_on
 * @property string $amount
 * @property Category|null $category
 * @property string $payee
 * @property PaymentMethod|null $payment_method
 * @property string|null $note
 * @property bool $exclude_from_budget
 * @property bool $needs_review
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'parent_id', 'booked_on', 'amount', 'category', 'payee', 'payment_method', 'note', 'exclude_from_budget', 'needs_review'])]
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
            'payment_method' => PaymentMethod::class,
            'exclude_from_budget' => 'boolean',
            'needs_review' => 'boolean',
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
     * Uncategorized top-level rows that have not been split away.
     *
     * @param  Builder<Transaction>  $query
     */
    public function scopeInbox(Builder $query): void
    {
        $query->whereNull('parent_id')
            ->whereDoesntHave('children')
            ->where(function (Builder $inner): void {
                $inner->whereNull('category')->orWhere('needs_review', true);
            });
    }
}
