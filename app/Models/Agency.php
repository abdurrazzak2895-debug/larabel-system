<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property string $name
 * @property string $code
 * @property bool $status
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class Agency extends Model
{
    use HasFactory;

    /** @var array<int, string> */
    protected $fillable = ['name', 'code', 'status'];

    /** @var array<string, string> */
    protected $casts = [
        'status' => 'boolean',
    ];

    /** @return HasMany<User> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return HasOne<AgencyWallet> */
    public function wallet(): HasOne
    {
        return $this->hasOne(AgencyWallet::class);
    }

    /** @return HasMany<PaccCredential> */
    public function credentials(): HasMany
    {
        return $this->hasMany(PaccCredential::class);
    }

    /** @return HasMany<Booking> */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /** @return HasMany<DepositRequest> */
    public function depositRequests(): HasMany
    {
        return $this->hasMany(DepositRequest::class);
    }

    /** @return HasMany<AuditLog> */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'actor_id');
    }
}
