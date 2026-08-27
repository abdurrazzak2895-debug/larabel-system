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
        'recovery_failures',
        'circuit_open_until',
        'last_recovered_at',
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
        'circuit_open_until' => 'datetime',
        'last_recovered_at' => 'datetime',
        'active' => 'boolean',
        'recovery_failures' => 'integer',
    ];

    public function scopeUsable(Builder $query): Builder
    {
        return $query
            ->where('active', true)
            ->where(function (Builder $query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now()->addSeconds(30));
            })
            ->where(function (Builder $query): void {
                $query->whereNull('circuit_open_until')
                    ->orWhere('circuit_open_until', '<=', now());
            });
    }

    public function hasUsableSession(int $skewSeconds = 30): bool
    {
        return $this->active
            && filled($this->session_cookie)
            && ($this->expires_at === null
                || $this->expires_at->isAfter(Carbon::now()->addSeconds($skewSeconds)))
            && ($this->circuit_open_until === null || $this->circuit_open_until->isPast());
    }

    public function circuitIsOpen(): bool
    {
        return $this->circuit_open_until !== null
            && $this->circuit_open_until->isFuture();
    }

    public function markRecoverySuccess(): void
    {
        $this->forceFill([
            'recovery_failures' => 0,
            'circuit_open_until' => null,
            'last_recovered_at' => now(),
            'last_checked_at' => now(),
            'last_error' => null,
        ])->saveQuietly();
    }

    public function markRecoveryFailure(string $message): void
    {
        $failures = ((int) $this->recovery_failures) + 1;
        $threshold = max(1, (int) config('portal.recovery_failure_threshold', 3));
        $circuitMinutes = max(1, (int) config('portal.recovery_circuit_minutes', 5));

        $this->forceFill([
            'recovery_failures' => $failures,
            'circuit_open_until' => $failures >= $threshold
                ? now()->addMinutes($circuitMinutes)
                : null,
            'last_checked_at' => now(),
            'last_error' => \Illuminate\Support\Str::limit($message, 500),
        ])->saveQuietly();
    }
}
