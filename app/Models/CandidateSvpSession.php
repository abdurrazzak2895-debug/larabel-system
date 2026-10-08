<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CandidateSvpSession extends Model
{
    protected $fillable = [
        'candidate_id',
        'user_id',
        'agency_id',
        'svp_user_id',
        'access_token',
        'csrf_token',
        'expires_at',
        'last_used_at',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'csrf_token' => 'encrypted',
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function usable(): bool
    {
        return filled($this->access_token)
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
