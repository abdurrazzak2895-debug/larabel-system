<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserWalletTransaction extends Model
{
    protected $fillable = ['user_wallet_id', 'type', 'amount', 'reference', 'meta'];

    protected $casts = [
        'user_wallet_id' => 'integer',
        'amount' => 'decimal:2',
        'meta' => 'array',
    ];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(UserWallet::class, 'user_wallet_id');
    }
}
