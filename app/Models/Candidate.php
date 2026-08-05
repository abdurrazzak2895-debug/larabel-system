<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Auto-generated candidate profile synced from the SVP / Takamol API.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $agency_id
 * @property string|null $svp_user_id
 * @property string $full_name
 * @property string|null $national_id
 * @property string|null $phone
 * @property string|null $email
 * @property array|null $svp_data
 */
class Candidate extends Model
{
    /** @var array<int, string> */
    protected $fillable = [
        'user_id',
        'agency_id',
        'svp_user_id',
        'full_name',
        'national_id',
        'phone',
        'email',
        'svp_data',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'svp_data' => 'array',
    ];

    /** @return BelongsTo<User, static> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Agency, static> */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }
}
