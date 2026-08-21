<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class SvpAvailabilityAccount extends Model
{
    protected $fillable = [
        'name',
        'email',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'last_used_at',
        'last_refreshed_at',
        'last_error',
        'active',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'token_expires_at' => 'datetime',
        'last_used_at' => 'datetime',
        'last_refreshed_at' => 'datetime',
        'active' => 'boolean',
    ];

    public function hasUsableToken(int $skewSeconds = 60): bool
    {
        return $this->active
            && filled($this->access_token)
            && ($this->token_expires_at === null
                || $this->token_expires_at->isAfter(Carbon::now()->addSeconds($skewSeconds)));
    }
}
