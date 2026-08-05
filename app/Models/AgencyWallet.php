<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $agency_id
 * @property string $available_balance
 * @property string $reserved_balance
 * @property string $credit_limit
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class AgencyWallet extends Model
{
    /** @var array<int, string> */
    protected $fillable = ['agency_id', 'available_balance', 'reserved_balance', 'credit_limit'];

    /** @var array<string, string> */
    protected $casts = [
        'id'                => 'integer',
        'agency_id'         => 'integer',
        'available_balance' => 'decimal:2',
        'reserved_balance'  => 'decimal:2',
        'credit_limit'      => 'decimal:2',
    ];

    /** @return BelongsTo<Agency, static> */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /** @return HasMany<WalletTransaction> */
    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class, 'wallet_id');
    }
}
