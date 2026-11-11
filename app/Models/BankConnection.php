<?php

namespace App\Models;

use App\Enums\BankConnectionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string|null $fin_inst
 * @property string|null $access_token
 * @property string|null $refresh_token
 * @property string $token_type
 * @property string|null $scope
 * @property Carbon|null $access_token_expires_at
 * @property Carbon|null $refresh_token_expires_at
 * @property BankConnectionStatus $status
 * @property Carbon|null $last_synced_at
 * @property string|null $last_sync_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'user_id',
    'fin_inst',
    'access_token',
    'refresh_token',
    'token_type',
    'scope',
    'access_token_expires_at',
    'refresh_token_expires_at',
    'status',
    'last_synced_at',
    'last_sync_error',
])]
#[Hidden(['access_token', 'refresh_token'])]
class BankConnection extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'access_token_expires_at' => 'datetime',
            'refresh_token_expires_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'status' => BankConnectionStatus::class,
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
     * @return HasMany<BankAccount, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(BankAccount::class);
    }

    public function forgetTokens(): void
    {
        $this->forceFill([
            'access_token' => null,
            'refresh_token' => null,
            'access_token_expires_at' => null,
            'refresh_token_expires_at' => null,
            'scope' => null,
            'status' => BankConnectionStatus::Disconnected,
            'last_sync_error' => null,
        ])->save();
    }
}
