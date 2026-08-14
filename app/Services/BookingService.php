<?php

namespace App\Services;

use App\Models\Agency;
use App\Models\Booking;
use App\Models\BookingAttempt;
use App\Models\BookingLog;
use App\Services\Providers\BookingProviderInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Orchestrates the full booking engine workflow:
 *
 *   Search Occupation → Search Test Center → Search Exam Session
 *   → Wallet Validation → Reserve Balance → Complete Booking
 *   → Success? → Save Booking → Wallet Debit → Notify User
 *   → Failure? → Release Reserved Balance → Refund → Notify User
 */
class BookingService
{
    public function __construct(
        private BookingProviderInterface $provider,
        private WalletService $wallet,
        private NotificationService $notifications,
        private AuditService $audit
    ) {}

    // -----------------------------------------------------------------
    // Read-only lookups (proxy to provider)
    // -----------------------------------------------------------------

    public function sessions(string $token, array $params = []) { return $this->provider->withToken($token)->examSessions($params); }
    public function availableDates(string $token, ?string $sessionId = null, array $params = []) { return $this->provider->withToken($token)->availableDates($sessionId, $params); }
    public function validateReservation(string $token) { return $this->provider->withToken($token)->validateReservation(); }
    public function reservations(string $token) { return $this->provider->withToken($token)->reservationDetails(); }
    public function reservation(string $token, string $id) { return $this->provider->withToken($token)->reservationDetails($id); }
    public function createReservation(string $token, array $payload) { return $this->provider->withToken($token)->createReservation($payload); }
    public function cancelReservation(string $token, string $id) { return $this->provider->withToken($token)->cancelReservation($id); }
    public function rescheduleReservation(string $token, string $id, array $payload) { return $this->provider->withToken($token)->rescheduleReservation($id, $payload); }
    public function useReservationCredit(string $token, array $payload) { return $this->provider->withToken($token)->useReservationCredit($payload); }
    public function examSession(string $token, string $id) { return $this->provider->withToken($token)->examSession($id); }
    public function occupations(string $token) { return $this->provider->withToken($token)->occupations(); }
    public function occupationsSearch(string $token, ?string $search = null, int $page = 1, int $perPage = 1000) { return $this->provider->withToken($token)->occupationsSearch($search, $page, $perPage); }
    public function cities(string $token, ?string $categoryId = null) { return $this->provider->withToken($token)->cities($categoryId); }
    public function testCenters(string $token, ?string $city = null, ?string $categoryId = null) { return $this->provider->withToken($token)->testCentersForFilters($city, $categoryId); }
    public function categories(string $token) { return $this->provider->withToken($token)->categories(); }
    public function countries(string $token) { return $this->provider->withToken($token)->countries(); }
    public function categoriesForOccupation(string $token, ?string $occupationId = null) { return $this->provider->withToken($token)->categoriesForOccupation($occupationId); }
    public function examConstraints(string $token) { return $this->provider->withToken($token)->examConstraints(); }
    public function examEngines(string $token) { return $this->provider->withToken($token)->examEngines(); }

    /**
     * Create a temporary seat reservation on the external API.
     *
     * @param  array<string, mixed>  $payload
     */
    public function temporarySeat(string $token, array $payload)
    {
        return $this->provider->withToken($token)->temporarySeat($payload);
    }

    // -----------------------------------------------------------------
    // Full booking workflow
    // -----------------------------------------------------------------

    /**
     * Execute the complete booking lifecycle.
     *
     * @param  array{
     *     agency_id: int,
     *     occupation_id?: string,
     *     category_id?: string,
     *     exam_session_id?: string,
     *     exam_session_name?: string,
     *     test_center_id?: string,
     *     test_center_name?: string,
     *     city?: string,
     *     exam_date?: string,
     *     amount: float,
     *     user_id?: int|null,
     *     credential_id?: int|null
     * }  $data
     * @return array{booking?: Booking, response?: mixed, success: bool, error?: string}
     */
    public function completeBooking(string $token, array $data, ?Booking $booking = null): array
    {
        $agencyId = (int) $data['agency_id'];
        $amount   = (float) $data['amount'];

        $booking = $booking ?? Booking::create([
            'agency_id'        => $agencyId,
            'user_id'          => $data['user_id'] ?? null,
            'credential_id'    => $data['credential_id'] ?? null,
            'occupation_id'    => $data['occupation_id'] ?? null,
            'category_id'      => $data['category_id'] ?? null,
            'exam_session_id'  => $data['exam_session_id'] ?? null,
            'exam_session_name' => $data['exam_session_name'] ?? null,
            'test_center_id'   => $data['test_center_id'] ?? null,
            'test_center_name' => $data['test_center_name'] ?? null,
            'exam_date'        => $data['exam_date'] ?? null,
            'notes'            => $data['notes'] ?? null,
            'booking_status'   => 'processing',
            'booking_reference' => Str::uuid()->toString(),
        ]);

        $this->audit->log($agencyId, 'booking', ['booking_id' => $booking->id, 'action' => 'started']);
        $this->logEvent($booking, 'booking_started', $data);

        try {
            // 1. Wallet validation & reserve balance
            $wallet = $this->wallet->getWallet($agencyId);
            if ((float) $wallet->available_balance < $amount) {
                throw new \RuntimeException('Insufficient wallet balance to complete booking.');
            }

            $this->wallet->hold($agencyId, $amount, $booking->booking_reference, [
                'booking_id' => $booking->id,
            ]);

            // 2. Persist a booking attempt before the external call
            $attempt = BookingAttempt::create([
                'booking_id'     => $booking->id,
                'status'         => 'processing',
                'request_payload' => $data,
            ]);

            // 3. Create the real SVP reservation. The selected exam_session_id
            // is the authoritative center assignment; site_id/site_city are
            // included as the wizard does for the selected center context.
            $provider = $this->provider->withToken($token);
            $reservationResponse = $provider->createReservation($this->reservationPayload($data));
            $reservationPayload = $reservationResponse->getData(true);
            $reservationId = $this->extractReservationId($reservationPayload);
            $providerResponse = ['reservation' => $reservationPayload];

            if ($reservationResponse->getStatusCode() < 200 || $reservationResponse->getStatusCode() >= 300 || ! $reservationId) {
                $attempt->update(['provider_response' => $providerResponse]);
                return $this->handleBookingFailure($booking, $attempt, $providerResponse, $amount);
            }

            // 4. SVP discards an unpaid reservation. Persist it only after the
            // reservation-credit payment succeeds for the same reservation.
            $paymentResponse = $provider->useReservationCredit([
                'methodology_type' => $data['methodology'] ?? config('svp.default_methodology', 'in_person'),
                'reservation_id'   => $this->numericOrString($reservationId),
                'occupation_id'    => $this->numericOrString($data['occupation_id'] ?? null),
            ]);
            $providerResponse['payment'] = $paymentResponse->getData(true);
            $attempt->update(['provider_response' => $providerResponse]);

            // 5. On success — save booking, wallet debit, notify
            if ($paymentResponse->getStatusCode() >= 200 && $paymentResponse->getStatusCode() < 300) {
                $booking->update([
                    'reservation_id' => (string) ($this->extractReservationId($paymentResponse->getData(true)) ?: $reservationId),
                    'booking_status' => 'booked',
                ]);
                $attempt->update(['status' => 'success']);

                $this->wallet->debit($agencyId, $amount, $booking->booking_reference, [
                    'booking_id' => $booking->id,
                ]);

                $this->logEvent($booking, 'booking_completed', ['response' => $providerResponse]);
                $this->audit->log($agencyId, 'booking', ['booking_id' => $booking->id, 'action' => 'completed']);

                if ($booking->user_id) {
                    $this->notifications->send(
                        $booking->user_id,
                        'Booking confirmed',
                        'Your booking was completed successfully.'
                    );
                }

                return ['booking' => $booking, 'response' => $paymentResponse, 'success' => true];
            }

            // 5. Failure — release hold + refund
            return $this->handleBookingFailure($booking, $attempt, $providerResponse, $amount);
        } catch (\Throwable $e) {
            if (isset($attempt)) {
                $attempt->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            }

            return $this->handleBookingFailure($booking, null, ['error' => $e->getMessage()], $amount);
        }
    }

    /**
     * Build the SVP reservation payload. The real session token determines the
     * center/date; test_center_id is therefore translated to SVP's site_id.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function reservationPayload(array $data): array
    {
        return array_filter([
            'exam_session_id'       => (string) ($data['exam_session_id'] ?? ''),
            'occupation_id'         => $this->numericOrString($data['occupation_id'] ?? null),
            'language_code'         => strtoupper((string) ($data['language_code'] ?? config('svp.default_language_code', 'LOABB'))),
            'methodology'           => $data['methodology'] ?? config('svp.default_methodology', 'in_person'),
            'site_id'               => isset($data['test_center_id']) ? (string) $data['test_center_id'] : null,
            'site_city'             => $data['city'] ?? null,
            'country_id'            => (int) config('svp.country_id', 78),
            'accept_declaration'    => true,
            'info_confirmation'    => true,
            'practical_confirmation' => true,
        ], static fn ($value): bool => $value !== null && $value !== '');
    }

    protected function numericOrString(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }

        return is_numeric($value) ? (int) $value : $value;
    }

    protected function extractReservationId(mixed $payload): string|int|null
    {
        if (! is_array($payload)) {
            return null;
        }

        foreach ([
            data_get($payload, 'exam_reservation.id'),
            data_get($payload, 'reservation.id'),
            $payload['reservation_id'] ?? null,
            $payload['reservationId'] ?? null,
            $payload['id'] ?? null,
        ] as $id) {
            if ($id !== null && $id !== '') {
                return is_scalar($id) ? $id : null;
            }
        }

        return null;
    }

    /**
     * Release reserved balance, mark booking failed, notify user.
     *
     * @return array{booking: Booking, success: bool, error: string}
     */
    protected function handleBookingFailure(Booking $booking, ?BookingAttempt $attempt, mixed $response, float $amount): array
    {
        $booking->update(['booking_status' => 'failed']);
        $this->logEvent($booking, 'booking_failed', ['response' => $response]);

        try {
            $this->wallet->releaseHold((int) $booking->agency_id, $amount, $booking->booking_reference, [
                'booking_id' => $booking->id,
            ]);

            $this->wallet->refund((int) $booking->agency_id, $amount, $booking->booking_reference, [
                'booking_id' => $booking->id,
            ]);
        } catch (\Throwable $e) {
            $this->logEvent($booking, 'refund_failed', ['error' => $e->getMessage()]);
        }

        if ($booking->user_id) {
            $this->notifications->send(
                $booking->user_id,
                'Booking failed',
                'Your booking could not be completed. Any reserved amount has been refunded.'
            );
        }

        $this->audit->log((int) $booking->agency_id, 'booking', ['booking_id' => $booking->id, 'action' => 'failed']);

        return ['booking' => $booking, 'success' => false, 'error' => 'Booking failed.'];
    }

    /**
     * Cancel a booked reservation and trigger refund.
     */
    public function cancelBooking(Booking $booking, float $amount, string $reason): array
    {
        return DB::transaction(function () use ($booking, $amount, $reason) {
            $booking->update(['booking_status' => 'cancelled']);

            $this->wallet->refund((int) $booking->agency_id, $amount, $booking->booking_reference, [
                'booking_id' => $booking->id,
            ]);

            $this->logEvent($booking, 'booking_cancelled', ['reason' => $reason]);
            $this->audit->log((int) $booking->agency_id, 'refund', ['booking_id' => $booking->id, 'action' => 'cancelled']);

            if ($booking->user_id) {
                $this->notifications->send(
                    $booking->user_id,
                    'Booking cancelled',
                    'Your booking was cancelled. Refund has been initiated.'
                );
            }

            return ['success' => true, 'booking' => $booking];
        });
    }

    // -----------------------------------------------------------------
    // Internal helpers
    // -----------------------------------------------------------------

    protected function logEvent(Booking $booking, string $event, mixed $payload = null, mixed $providerResponse = null): void
    {
        BookingLog::create([
            'booking_id'        => $booking->id,
            'event_type'        => $event,
            'payload'           => $payload ? (array) $payload : null,
            'provider_response' => $providerResponse ? (array) $providerResponse : null,
        ]);
    }
}
