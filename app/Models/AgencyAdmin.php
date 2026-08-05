<?php

namespace App\Models;

use App\Models\Concerns\HasRoles;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgencyAdmin extends Model
{
    use HasFactory;
    use HasRoles;

    protected $table = 'agency_admins';

    protected $fillable = ['agency_id', 'name', 'email', 'password', 'status'];

    protected $casts = [
        'status'   => 'boolean',
        'password' => 'hashed',
    ];

    protected $guard = 'agency';

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }
}