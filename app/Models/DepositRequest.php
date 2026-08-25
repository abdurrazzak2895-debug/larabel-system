<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $agency_id
 * @property int|null $user_id
 * @property string $amount
 * @property string $payment_method
 * @property string|null $receipt_path
 * @property string|null $mfs_sender_phone
 * @property string|null $mfs_transaction_id
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $processed_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class DepositRequest extends Model
{
    /** @var array<int, string> */
    protected $fillable = ['agency_id', 'user_id', 'amount', 'payment_method', 'receipt_path', 'mfs_sender_phone', 'mfs_transaction_id', 'status', 'processed_at'];

    /** @var array<string, string> */
    protected $casts = [
        'amount'       => 'decimal:2',
        'processed_at' => 'datetime',
    ];

    /** @return BelongsTo<Agency, static> */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /** @return BelongsTo<User, static> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
