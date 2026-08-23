<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class PortalAvailabilityApiKey extends Model
{
    protected $fillable = [
        'portal_availability_credential_id',
        'name',
        'key_prefix',
        'key_hash',
        'expires_at',
        'rate_limit_per_minute',
    ];

    protected $hidden = [
        'key_hash',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
        'rate_limit_per_minute' => 'integer',
    ];

    public function credential(): BelongsTo
    {
        return $this->belongsTo(PortalAvailabilityCredential::class, 'portal_availability_credential_id');
    }

    public function scopeUsable(Builder $query): Builder
    {
        return $query
            ->whereNull('revoked_at')
            ->where(function (Builder $query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', Carbon::now());
            });
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture())
            && $this->credential instanceof PortalAvailabilityCredential
            && $this->credential->hasUsableSession();
    }

    public static function generatePlaintext(): string
    {
        return 'pav_live_'.Str::random(48);
    }

    public static function hashPlaintext(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    public static function prefix(string $plaintext): string
    {
        return substr($plaintext, 0, 16);
    }
}
