<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tracks retries and failures for a booking.
 *
 * @property int $id
 * @property int $booking_id
 * @property string $status
 * @property array<string, mixed>|null $request_payload
 * @property array<string, mixed>|null $provider_response
 * @property string|null $error_message
 * @property \Illuminate\Support\Carbon $created_at
 */
class BookingAttempt extends Model
{
    protected $table = 'booking_attempts';

    /** @var array<int, string> */
    protected $fillable = ['booking_id', 'status', 'request_payload', 'provider_response', 'error_message'];

    /** @var array<string, string> */
    protected $casts = [
        'request_payload'   => 'array',
        'provider_response' => 'array',
    ];

    public $timestamps = true;

    /** @return BelongsTo<Booking, static> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
