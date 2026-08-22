<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class PortalAvailabilityCredential extends Model
{
    protected $fillable = [
        'name',
        'portal_account_id',
        'session_cookie',
        'expires_at',
        'last_used_at',
        'last_checked_at',
        'last_error',
        'active',
    ];

    protected $hidden = [
        'session_cookie',
    ];

    protected $casts = [
        'session_cookie' => 'encrypted',
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
        'last_checked_at' => 'datetime',
        'active' => 'boolean',
    ];

    public function scopeUsable(Builder $query): Builder
    {
        return $query
            ->where('active', true)
            ->where(function (Builder $query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now()->addSeconds(30));
            });
    }

    public function hasUsableSession(int $skewSeconds = 30): bool
    {
        return $this->active
            && filled($this->session_cookie)
            && ($this->expires_at === null
                || $this->expires_at->isAfter(Carbon::now()->addSeconds($skewSeconds)));
    }
}
