<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable event log for a booking.
 *
 * @property int $id
 * @property int $booking_id
 * @property string $event_type
 * @property array<string, mixed>|null $payload
 * @property string|null $provider_response
 * @property \Illuminate\Support\Carbon $created_at
 */
class BookingLog extends Model
{
    protected $table = 'booking_logs';

    /** @var array<int, string> */
    protected $fillable = ['booking_id', 'event_type', 'payload', 'provider_response'];

    /** @var array<string, string> */
    protected $casts = [
        'payload'           => 'array',
        'provider_response' => 'array',
    ];

    public $timestamps = true;

    /** @return BelongsTo<Booking, static> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
