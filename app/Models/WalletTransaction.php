<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable financial ledger entry — never update once persisted.
 *
 * @property int $id
 * @property int $wallet_id
 * @property string $type        // deposit | booking_hold | booking_debit | refund | manual_adjustment
 * @property string $amount
 * @property string|null $reference
 * @property array<string, mixed>|null $meta
 * @property \Illuminate\Support\Carbon $created_at
 */
class WalletTransaction extends Model
{
    protected $table = 'wallet_transactions';

    /** @var array<int, string> */
    protected $fillable = ['wallet_id', 'type', 'amount', 'reference', 'meta'];

    /** @var array<string, string> */
    protected $casts = [
        'amount' => 'decimal:2',
        'meta'   => 'array',
    ];

    public $timestamps = true;

    /** @return BelongsTo<AgencyWallet, static> */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(AgencyWallet::class);
    }
}
