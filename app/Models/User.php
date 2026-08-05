<?php

namespace App\Models;

use App\Models\Concerns\HasRoles;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * @property int $id
 * @property int|null $agency_id
 * @property string $name
 * @property string|null $username
 * @property string|null $email
 * @property string $password
 * @property bool $status
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class User extends Authenticatable
{
    use HasFactory;
    use HasRoles;

    /** @var array<int, string> */
    protected $fillable = ['agency_id', 'name', 'username', 'email', 'password', 'status'];

    /** @var array<int, string> */
    protected $hidden = ['password', 'remember_token'];

    /** @var array<string, string> */
    protected $casts = [
        'status'   => 'boolean',
        'password' => 'hashed',
    ];

    /** @return BelongsTo<Agency, static> */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /** @return HasMany<Booking> */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /** @return HasMany<Notification> */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    /** @return HasOneThrough<AgencyWallet> */
    public function wallet(): HasOneThrough
    {
        return $this->hasOneThrough(AgencyWallet::class, Agency::class, 'id', 'agency_id', 'agency_id', 'id');
    }
}
