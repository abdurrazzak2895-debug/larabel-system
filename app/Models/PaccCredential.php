<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stores PACC / external booking API credentials per agency.
 *
 * @property int $id
 * @property int $agency_id
 * @property string $api_token
 * @property string|null $refresh_token
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property bool $active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class PaccCredential extends Model
{
    /** @var array<int, string> */
    protected $fillable = ['agency_id', 'api_token', 'refresh_token', 'expires_at', 'active'];

    /** @var array<string, string> */
    protected $casts = [
        'expires_at' => 'datetime',
        'active'     => 'boolean',
    ];

    /** @var array<int, string> */
    protected $hidden = ['api_token', 'refresh_token'];

    /** @return BelongsTo<Agency, static> */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }
}
