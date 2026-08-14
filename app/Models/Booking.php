<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $agency_id
 * @property int|null $user_id
 * @property int|null $credential_id
 * @property string|null $occupation_id
 * @property string|null $category_id
 * @property string|null $exam_session_id
 * @property string|null $exam_session_name
 * @property string|null $test_center_id
 * @property string|null $test_center_name
 * @property string|null $exam_date
 * @property string|null $temporary_hold_id
 * @property string|null $temporary_hold_expires_at
 * @property string|null $reservation_id
 * @property string $booking_status // pending | processing | booked | failed | cancelled | refunded
 * @property string|null $booking_reference
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
class Booking extends Model
{
    use SoftDeletes;

    /** @var array<int, string> */
    protected $fillable = [
        'agency_id',
        'user_id',
        'credential_id',
        'occupation_id',
        'category_id',
        'exam_session_id',
        'exam_session_name',
        'test_center_id',
        'test_center_name',
        'exam_date',
        'temporary_hold_id',
        'temporary_hold_expires_at',
        'reservation_id',
        'booking_status',
        'booking_reference',
        'notes',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'exam_date' => 'date',
    ];

    /** @return BelongsTo<Agency, static> */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /** @return BelongsTo<User, static> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Candidate, static> */
    public function credential(): BelongsTo
    {
        return $this->belongsTo(Candidate::class, 'credential_id');
    }

    /** @return HasMany<BookingLog> */
    public function logs(): HasMany
    {
        return $this->hasMany(BookingLog::class);
    }

    /** @return HasMany<BookingAttempt> */
    public function attempts(): HasMany
    {
        return $this->hasMany(BookingAttempt::class);
    }

    /** @return HasMany<RefundRequest> */
    public function refundRequests(): HasMany
    {
        return $this->hasMany(RefundRequest::class);
    }
}
