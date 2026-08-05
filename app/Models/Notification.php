<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $title
 * @property string $body
 * @property \Illuminate\Support\Carbon|null $read_at
 * @property \Illuminate\Support\Carbon $created_at
 */
class Notification extends Model
{
    /** @var array<int, string> */
    protected $fillable = ['user_id', 'title', 'body', 'read_at'];

    /** @var array<string, string> */
    protected $casts = [
        'read_at' => 'datetime',
    ];

    /** @return BelongsTo<User, static> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
