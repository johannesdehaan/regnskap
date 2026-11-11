<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $bank_connection_id
 * @property int $user_id
 * @property string $external_key
 * @property string $name
 * @property string|null $account_number
 * @property string|null $account_type
 * @property string $currency_code
 * @property PaymentMethod|null $payment_method
 * @property bool $sync_enabled
 */
#[Fillable([
    'bank_connection_id',
    'user_id',
    'external_key',
    'name',
    'account_number',
    'account_type',
    'currency_code',
    'payment_method',
    'sync_enabled',
])]
class BankAccount extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payment_method' => PaymentMethod::class,
            'sync_enabled' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<BankConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(BankConnection::class, 'bank_connection_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function displayNumber(): string
    {
        $digits = preg_replace('/\D+/', '', $this->account_number ?? '') ?? '';

        if (strlen($digits) === 11) {
            return substr($digits, 0, 4).'.'.substr($digits, 4, 2).'.'.substr($digits, 6);
        }

        if ($digits === '') {
            return '';
        }

        return '····'.substr($digits, -4);
    }
}
