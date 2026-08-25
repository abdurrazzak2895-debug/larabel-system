<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserWallet extends Model
{
    protected $fillable = ['user_id', 'available_balance', 'reserved_balance', 'credit_limit'];

    protected $casts = [
        'user_id'           => 'integer',
        'available_balance' => 'decimal:2',
        'reserved_balance'  => 'decimal:2',
        'credit_limit'      => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(UserWalletTransaction::class);
    }
}
