<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class TakamolApiConsumerKey extends Model
{
    protected $table = 'takamol_api_consumer_keys';

    protected $fillable = [
        'name',
        'key_prefix',
        'key_hash',
        'expires_at',
        'rate_limit_per_minute',
    ];

    protected $hidden = ['key_hash'];

    protected $casts = [
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
        'rate_limit_per_minute' => 'integer',
    ];

    public function scopeUsable(Builder $query): Builder
    {
        return $query
            ->whereNull('revoked_at')
            ->where(function (Builder $query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public static function generatePlaintext(): string
    {
        return 'takamol_live_'.Str::random(64);
    }

    public static function prefix(string $plaintext): string
    {
        return substr($plaintext, 0, 16);
    }

    public static function hashPlaintext(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }
}
