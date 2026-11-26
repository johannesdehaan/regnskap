<?php

namespace App\Models;

use App\Enums\HoldingType;
use Carbon\CarbonImmutable;
use Database\Factories\HoldingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $value
 * @property string|null $price
 * @property string|null $quantity
 * @property HoldingType $type
 * @property string|null $ticker
 * @property string|null $isin
 * @property CarbonImmutable|null $price_updated_at
 */
#[Fillable(['user_id', 'name', 'value', 'price', 'quantity', 'type', 'ticker', 'isin', 'price_updated_at'])]
class Holding extends Model
{
    /** @use HasFactory<HoldingFactory> */
    use HasFactory;

    /**
     * Yahoo Finance symbols, for example EQNR.OL, ^OSEAX or EURNOK=X.
     */
    public const TICKER_PATTERN = '/^[A-Za-z0-9.\-^=&]+$/';

    public const TICKER_MAX_LENGTH = 32;

    public const ISIN_PATTERN = '/^[A-Za-z]{2}[A-Za-z0-9]{9}[0-9]$/';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'price' => 'decimal:4',
            'quantity' => 'decimal:6',
            'type' => HoldingType::class,
            'price_updated_at' => 'datetime',
        ];
    }

    /**
     * A ticker or an ISIN the way it is stored: trimmed, in upper case, and null when empty.
     */
    public static function code(?string $value): ?string
    {
        $code = strtoupper(trim((string) $value));

        return $code === '' ? null : $code;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
