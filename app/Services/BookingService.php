<?php

namespace App\Services;

use App\Models\Agency;
use App\Models\Booking;
use App\Models\BookingAttempt;
use App\Models\BookingLog;
use App\Models\Setting;
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
        private SvpReservationCreditService $credits,
        private NotificationService $notifications,
        private AuditService $audit,
        private WalletService $wallet
    ) {}

    // -----------------------------------------------------------------
    // Read-only lookups (proxy to provider)
    // -----------------------------------------------------------------

    public function sessions(string $token, array $params = []) { return $this->provider->withToken($token)->examSessions($params); }
    public function sessionsForCenter(string $token, array $params = []) { return $this->provider->withToken($token)->examSessionsForCenter($params); }
    public function availableDates(string $token, ?string $sessionId = null, array $params = []) { return $this->provider->withToken($token)->availableDates($sessionId, $params); }
    public function validateReservation(string $token) { return $this->provider->withToken($token)->validateReservation(); }
    public function reservations(string $token) { return $this->provider->withToken($token)->reservationDetails(); }
    public function reservation(string $token, string $id) { return $this->provider->withToken($token)->reservationDetails($id); }
    public function ticketPdf(string $token, string $reservationId, ?string $filename = null) { return $this->provider->withToken($token)->ticketPdf($reservationId, $filename); }
    public function createReservation(string $token, array $payload) { return $this->provider->withToken($token)->createReservation($payload); }
    public function cancelReservation(string $token, string $id) { return $this->provider->withToken($token)->cancelReservation($id); }
    public function rescheduleReservation(string $token, string $id, array $payload) { return $this->provider->withToken($token)->rescheduleReservation($id, $payload); }
    public function useReservationCredit(string $token, array $payload) { return $this->provider->withToken($token)->useReservationCredit($payload); }
    public function getPaymentStatus(string $token, string $resourcePath) { return $this->provider->withToken($token)->getPaymentStatus($resourcePath); }
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
     *     temporary_hold_id?: string,
     *     temporary_hold_expires_at?: string,
     *     svp_user_id?: string,
     *     user_id?: int|null,
     *     credential_id?: int|null
     * }  $data
     * @return array{booking?: Booking, response?: mixed, success: bool, error?: string}
     */
    public function completeBooking(string $token, array $data, ?Booking $booking = null): array
    {
        $agencyId = (int) $data['agency_id'];

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
            'temporary_hold_id' => $data['temporary_hold_id'] ?? null,
            'temporary_hold_expires_at' => $data['temporary_hold_expires_at'] ?? null,
            'notes'            => $data['notes'] ?? null,
            'booking_status'   => 'processing',
            'booking_reference' => Str::uuid()->toString(),
        ]);

        $this->audit->log($agencyId, 'booking', ['booking_id' => $booking->id, 'action' => 'started']);
        $this->logEvent($booking, 'booking_started', $data);

        try {
            // 1. Persist an attempt before the real SVP reservation call. The
            // Agency portal service fee below is separate from the SVP amount;
            // SVP still determines whether credit or card checkout is used.
            //
            $attempt = BookingAttempt::create([
                'booking_id'     => $booking->id,
                'status'         => 'processing',
                'request_payload' => $data,
            ]);

            // The portal booking fee belongs to the Agency wallet and is
            // completely separate from the amount/credit decision made by SVP.
            // Hold it before touching SVP, then debit only after SVP confirms the
            // reservation. This prevents a successful external booking without
            // the portal fee being accounted for.
            $portalFee = $this->portalBookingFee($agencyId);
            $portalFeeReference = $this->portalFeeReference($booking);
            if ($portalFee > 0
                && ! $this->walletTransaction($booking, 'booking_hold', $portalFeeReference)
                && ! $this->walletTransaction($booking, 'booking_debit', $portalFeeReference)) {
                $this->wallet->hold(
                    $agencyId,
                    $portalFee,
                    $portalFeeReference,
                    ['booking_id' => $booking->id, 'purpose' => 'portal_booking_fee']
                );
            }

            // 2. Create the real SVP reservation. The selected exam_session_id
            // is the authoritative center assignment; site_id/site_city are
            // included as the wizard does for the selected center context.
            $provider = $this->provider->withToken($token);
            $reservationResponse = $provider->createReservation($this->reservationPayload($data));
            $reservationPayload = $reservationResponse->getData(true);
            $reservationId = $this->extractReservationId($reservationPayload);
            $providerResponse = ['reservation' => $reservationPayload];

            if ($reservationResponse->getStatusCode() < 200 || $reservationResponse->getStatusCode() >= 300 || ! $reservationId) {
                $attempt->update(['provider_response' => $providerResponse]);
                return $this->handleBookingFailure($booking, $attempt, $providerResponse);
            }

            // SVP may echo the requested site_id in prometric_data while
            // assigning a different physical test_center. The physical center
            // returned by SVP is authoritative; never continue to payment when
            // it differs from the center selected in the Laravel wizard.
            $centerValidation = $this->validateReturnedReservationCenter(
                $reservationPayload,
                $data['test_center_id'] ?? null,
                $data['test_center_name'] ?? null
            );
            $providerResponse['center_validation'] = $centerValidation;

            if (! $centerValidation['valid']) {
                $cancelResponse = $provider->cancelReservation((string) $reservationId);
                $providerResponse['cancel_after_center_mismatch'] = $cancelResponse->getData(true);
                $error = $centerValidation['error'];
                $attempt->update([
                    'status' => 'failed',
                    'provider_response' => $providerResponse,
                    'error_message' => $error,
                ]);

                return $this->handleBookingFailure($booking, $attempt, $providerResponse, $error);
            }

            // 3. Re-read the live credit balance immediately before payment. A
            // positive balance consumes an SVP reservation credit; zero credit
            // creates an SVP-hosted card checkout instead. The user never enters
            // an amount in this application.
            $methodology = $data['methodology'] ?? config('svp.default_methodology', 'in_person');
            $creditStatus = $this->credits->statusForUser(
                $token,
                (string) ($data['svp_user_id'] ?? ''),
                (string) ($data['occupation_id'] ?? ''),
                (string) $methodology
            );
            $providerResponse['credit_status'] = ['credits' => $creditStatus['credits']];

            if ($creditStatus['credits'] > 0) {
                $paymentResponse = $provider->useReservationCredit([
                    'methodology_type' => $methodology,
                    'reservation_id' => $this->numericOrString($reservationId),
                    'occupation_id' => $this->numericOrString($data['occupation_id'] ?? null),
                ]);
                $providerResponse['credit_payment'] = $paymentResponse->getData(true);
                $attempt->update(['provider_response' => $providerResponse]);

                if ($paymentResponse->getStatusCode() >= 200 && $paymentResponse->getStatusCode() < 300) {
                    return $this->markCreditBookingComplete($booking, $attempt, $reservationId, $providerResponse, $paymentResponse);
                }
            }

            // The user has no usable SVP reservation credit (or it was depleted
            // concurrently), so create an SVP card checkout for this exact reservation.
            $checkoutResponse = $provider->createPayment([
                'payment_method' => 'card',
                'payable_type' => 'Reservation',
                'payable_id' => $this->numericOrString($reservationId),
            ]);
            $checkoutPayload = $checkoutResponse->getData(true);
            $providerResponse['checkout'] = is_array($checkoutPayload['checkout'] ?? null)
                ? $checkoutPayload['checkout']
                : $checkoutPayload;

            // Persist the exact transaction-specific URL when SVP provides one.
            // Some SVP responses intentionally return only a generic
            // eu-prod.oppwa.com host and the official COPYandPAY widget data.
            $checkoutUrl = $this->extractCheckoutUrl($providerResponse);
            $widgetCheckout = $this->widgetCheckoutFromProviderResponse($providerResponse);
            if (is_string($checkoutUrl) && $checkoutUrl !== '') {
                $providerResponse['checkout_url'] = $checkoutUrl;
            }
            if ($widgetCheckout !== null) {
                $providerResponse['widget_checkout'] = $widgetCheckout;
            }
            $attempt->update(['provider_response' => $providerResponse]);

            $checkoutCreated = $checkoutResponse->getStatusCode() >= 200
                && $checkoutResponse->getStatusCode() < 300
                && ((is_string($checkoutUrl) && $checkoutUrl !== '') || $widgetCheckout !== null);

            if ($checkoutCreated) {
                $booking->update([
                    'reservation_id' => (string) $reservationId,
                    'booking_status' => 'pending',
                ]);
                $attempt->update(['status' => 'payment_required']);
                $this->logEvent($booking, 'svp_checkout_created', ['reservation_id' => $reservationId], $providerResponse);

                return [
                    'booking' => $booking,
                    'response' => $checkoutResponse,
                    'success' => true,
                    'payment_required' => true,
                    'checkout_url' => $checkoutUrl,
                    'widget_checkout' => $widgetCheckout,
                ];
            }

            return $this->handleBookingFailure($booking, $attempt, $providerResponse);
        } catch (\Throwable $e) {
            if (isset($attempt)) {
                $attempt->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            }

            return $this->handleBookingFailure($booking, null, ['error' => $e->getMessage()]);
        }
    }

    /**
     * Complete a reschedule against the existing SVP reservation ID.
     *
     * Unlike a fresh booking, SVP keeps the reservation ID while changing the
     * selected session/date. The local Booking row is a new audit/payment
     * record, so portal fees remain independently traceable and are finalized
     * only after SVP credit or card payment succeeds.
     *
     * @param array<string, mixed> $data
     * @return array{booking?: Booking, response?: mixed, success: bool, error?: string, payment_required?: bool}
     */
    public function completeReschedule(string $token, string $reservationId, array $data): array
    {
        $agencyId = (int) $data['agency_id'];
        $booking = Booking::create([
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
            'temporary_hold_id' => $data['temporary_hold_id'] ?? null,
            'temporary_hold_expires_at' => $data['temporary_hold_expires_at'] ?? null,
            'reservation_id'   => $reservationId,
            'notes'            => $data['notes'] ?? null,
            'booking_status'   => 'processing',
            'booking_reference' => Str::uuid()->toString(),
        ]);

        $this->audit->log($agencyId, 'booking', ['booking_id' => $booking->id, 'action' => 'reschedule_started', 'reservation_id' => $reservationId]);
        $this->logEvent($booking, 'reschedule_started', $data + ['reservation_id' => $reservationId]);

        try {
            $attempt = BookingAttempt::create([
                'booking_id' => $booking->id,
                'status' => 'processing',
                'request_payload' => $data + ['reservation_id' => $reservationId],
            ]);

            $portalFee = $this->portalBookingFee($agencyId);
            $portalFeeReference = $this->portalFeeReference($booking);
            if ($portalFee > 0
                && ! $this->walletTransaction($booking, 'booking_hold', $portalFeeReference)
                && ! $this->walletTransaction($booking, 'booking_debit', $portalFeeReference)) {
                $this->wallet->hold($agencyId, $portalFee, $portalFeeReference, [
                    'booking_id' => $booking->id,
                    'purpose' => 'portal_booking_fee_reschedule',
                    'reservation_id' => $reservationId,
                ]);
            }

            $provider = $this->provider->withToken($token);
            $rescheduleResponse = $provider->rescheduleReservation($reservationId, [
                'exam_session_id' => (string) $data['exam_session_id'],
                'exam_date' => (string) $data['exam_date'],
                'methodology' => $data['methodology'] ?? config('svp.default_methodology', 'in_person'),
            ]);
            $reschedulePayload = $rescheduleResponse->getData(true);
            $providerResponse = ['reschedule' => $reschedulePayload];

            if ($rescheduleResponse->getStatusCode() < 200 || $rescheduleResponse->getStatusCode() >= 300) {
                $attempt->update(['provider_response' => $providerResponse]);
                return $this->handleBookingFailure($booking, $attempt, $providerResponse, 'SVP could not reschedule the reservation.');
            }

            // Read the reservation back from SVP. This response is authoritative
            // for the physical center after an opaque session ID is accepted.
            $verificationResponse = $provider->reservationDetails($reservationId);
            $verificationPayload = $verificationResponse->getData(true);
            $providerResponse['verification'] = $verificationPayload;
            $centerValidation = $this->validateReturnedReservationCenter($verificationPayload, $data['test_center_id'] ?? null);
            $providerResponse['center_validation'] = $centerValidation;

            if (! $centerValidation['valid']) {
                $attempt->update([
                    'status' => 'failed',
                    'provider_response' => $providerResponse,
                    'error_message' => $centerValidation['error'],
                ]);
                return $this->handleBookingFailure($booking, $attempt, $providerResponse, $centerValidation['error']);
            }

            $methodology = $data['methodology'] ?? config('svp.default_methodology', 'in_person');
            $creditStatus = $this->credits->statusForUser(
                $token,
                (string) ($data['svp_user_id'] ?? ''),
                (string) ($data['occupation_id'] ?? ''),
                (string) $methodology
            );
            $providerResponse['credit_status'] = ['credits' => $creditStatus['credits']];

            if ($creditStatus['credits'] > 0) {
                $creditResponse = $provider->useReservationCredit([
                    'methodology_type' => $methodology,
                    'reservation_id' => $this->numericOrString($reservationId),
                    'occupation_id' => $this->numericOrString($data['occupation_id'] ?? null),
                ]);
                $providerResponse['credit_payment'] = $creditResponse->getData(true);
                $attempt->update(['provider_response' => $providerResponse]);

                if ($creditResponse->getStatusCode() >= 200 && $creditResponse->getStatusCode() < 300) {
                    return $this->markCreditBookingComplete($booking, $attempt, $reservationId, $providerResponse, $creditResponse);
                }
            }

            $checkoutResponse = $provider->createPayment([
                'payment_method' => 'card',
                'payable_type' => 'Reservation',
                'payable_id' => $this->numericOrString($reservationId),
            ]);
            $checkoutPayload = $checkoutResponse->getData(true);
            $providerResponse['checkout'] = is_array($checkoutPayload['checkout'] ?? null)
                ? $checkoutPayload['checkout']
                : $checkoutPayload;
            $checkoutUrl = $this->extractCheckoutUrl($providerResponse);
            $widgetCheckout = $this->widgetCheckoutFromProviderResponse($providerResponse);
            if (is_string($checkoutUrl) && $checkoutUrl !== '') {
                $providerResponse['checkout_url'] = $checkoutUrl;
            }
            if ($widgetCheckout !== null) {
                $providerResponse['widget_checkout'] = $widgetCheckout;
            }
            $attempt->update(['provider_response' => $providerResponse]);

            $checkoutCreated = $checkoutResponse->getStatusCode() >= 200
                && $checkoutResponse->getStatusCode() < 300
                && ((is_string($checkoutUrl) && $checkoutUrl !== '') || $widgetCheckout !== null);

            if ($checkoutCreated) {
                $booking->update(['booking_status' => 'pending']);
                $attempt->update(['status' => 'payment_required']);
                $this->logEvent($booking, 'svp_reschedule_checkout_created', ['reservation_id' => $reservationId], $providerResponse);

                return [
                    'booking' => $booking,
                    'response' => $checkoutResponse,
                    'success' => true,
                    'payment_required' => true,
                    'checkout_url' => $checkoutUrl,
                    'widget_checkout' => $widgetCheckout,
                ];
            }

            return $this->handleBookingFailure($booking, $attempt, $providerResponse, 'SVP could not create a card checkout for the rescheduled reservation.');
        } catch (\Throwable $e) {
            if (isset($attempt)) {
                $attempt->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            }

            return $this->handleBookingFailure($booking, $attempt ?? null, ['error' => $e->getMessage()], 'Could not complete the SVP reschedule.');
        }
    }

    /**
     * Persist the completed state after SVP accepted a reservation credit.
     *
     * @param array<string, mixed> $providerResponse
     */
    protected function markCreditBookingComplete(
        Booking $booking,
        BookingAttempt $attempt,
        string|int $reservationId,
        array $providerResponse,
        mixed $paymentResponse
    ): array {
        $paymentPayload = $paymentResponse->getData(true);
        $booking->update([
            'reservation_id' => (string) ($this->extractReservationId($paymentPayload) ?: $reservationId),
            'booking_status' => 'booked',
        ]);
        $attempt->update(['status' => 'success']);

        $this->finalizePortalBookingFee($booking);

        $this->logEvent($booking, 'booking_completed_with_svp_credit', ['response' => $providerResponse]);
        $this->audit->log((int) $booking->agency_id, 'booking', ['booking_id' => $booking->id, 'action' => 'completed_with_svp_credit']);

        if ($booking->user_id) {
            $this->notifications->send(
                $booking->user_id,
                'Booking confirmed',
                'Your SVP reservation credit was used and the booking was completed successfully.'
            );
        }

        return ['booking' => $booking, 'response' => $paymentResponse, 'success' => true];
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
            'hold_id'               => isset($data['temporary_hold_id']) ? $this->numericOrString($data['temporary_hold_id']) : null,
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
     * Validate the physical center returned by SVP after reservation creation.
     * `prometric_data.site_id` is only a request echo in some responses, so a
     * nested `test_center` value is preferred whenever SVP provides it.
     *
     * @return array{valid: bool, selected_center_id: ?string, returned_center_id: ?string, selected_center_name: string, returned_center_name: ?string, metadata_present: bool, error?: string}
     */
    protected function validateReturnedReservationCenter(array $payload, mixed $selectedCenterId, mixed $selectedCenterName = null): array
    {
        $selected = trim((string) ($selectedCenterId ?? ''));
        $selectedName = $this->centerDisplayName($selected, $selectedCenterName);
        $returned = null;

        foreach ([
            'reservation.test_center.test_center_id',
            'reservation.test_center.id',
            'exam_reservation.test_center.test_center_id',
            'exam_reservation.test_center.id',
            'test_center.test_center_id',
            'test_center.id',
        ] as $path) {
            $value = data_get($payload, $path);
            if (is_scalar($value) && trim((string) $value) !== '') {
                $returned = trim((string) $value);
                break;
            }
        }

        // If SVP returns no physical center object, use site_id only as a
        // fallback. It is still better than silently accepting a different
        // center, while a detailed center object always takes precedence.
        if ($returned === null) {
            foreach ([
                'reservation.prometric_data.site_id',
                'exam_reservation.prometric_data.site_id',
                'prometric_data.site_id',
            ] as $path) {
                $value = data_get($payload, $path);
                if (is_scalar($value) && trim((string) $value) !== '') {
                    $returned = trim((string) $value);
                    break;
                }
            }
        }

        $returnedName = $returned === null ? null : $this->centerDisplayName(
            $returned,
            $this->centerNameFromPayload($payload)
        );

        if ($selected === '' || $returned === null) {
            return [
                'valid' => true,
                'selected_center_id' => $selected !== '' ? $selected : null,
                'returned_center_id' => $returned,
                'selected_center_name' => $selectedName,
                'returned_center_name' => $returnedName,
                'metadata_present' => $returned !== null,
            ];
        }

        $valid = $returned === $selected;

        return [
            'valid' => $valid,
            'selected_center_id' => $selected,
            'returned_center_id' => $returned,
            'selected_center_name' => $selectedName,
            'returned_center_name' => $returnedName,
            'metadata_present' => true,
            ...(! $valid ? [
                'error' => sprintf(
                    'SVP assigned test center %s, but the selected center was %s. The reservation was canceled for safety.',
                    $returnedName ?? 'another test center',
                    $selectedName
                ),
            ] : []),
        ];
    }

    /**
     * Resolve the best human-readable test-center name from an SVP response.
     */
    protected function centerNameFromPayload(array $payload): ?string
    {
        foreach ([
            'reservation.test_center.name',
            'reservation.test_center.english_name',
            'reservation.test_center.test_center_name',
            'exam_reservation.test_center.name',
            'exam_reservation.test_center.english_name',
            'exam_reservation.test_center.test_center_name',
            'test_center.name',
            'test_center.english_name',
            'test_center.test_center_name',
        ] as $path) {
            $value = data_get($payload, $path);
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    /**
     * Resolve a display-only name without exposing an opaque numeric ID.
     */
    protected function centerDisplayName(?string $centerId, mixed $providedName = null): string
    {
        foreach ((array) config('svp.dhaka_test_centers', []) as $center) {
            if ((string) data_get($center, 'id') === (string) $centerId) {
                return (string) (data_get($center, 'name')
                    ?: data_get($center, 'english_name')
                    ?: 'Test center');
            }
        }

        $provided = trim((string) ($providedName ?? ''));
        if ($provided !== '' && ! in_array(strtolower($provided), ['unknown center', 'unknown test center'], true)) {
            return $provided;
        }

        return $centerId ? 'Test center' : 'Selected test center';
    }

    /**
     * Normalize the official SVP/HyperPay checkout URL from supported response
     * shapes, including the identifiers shown in the supplied Postman flow.
     */
    public function checkoutUrlFromProviderResponse(array $providerResponse): ?string
    {
        return $this->extractCheckoutUrl($providerResponse);
    }

    /**
     * Extract the HyperPay COPYandPAY widget payload from an SVP checkout.
     *
     * SVP nests the HyperPay checkout identifier under checkout.response.ndc
     * (and mirrors it under checkout.response.id) and returns the SHA-384
     * integrity token alongside it. The local SVP checkout.id is not the
     * identifier required by paymentWidgets.js.
     *
     * @return array{checkout_id: string, integrity: ?string}|null
     */
    public function widgetCheckoutFromProviderResponse(array $providerResponse): ?array
    {
        $checkoutId = data_get($providerResponse, 'checkout.response.ndc')
            ?? data_get($providerResponse, 'checkout.response.id')
            ?? data_get($providerResponse, 'data.checkout.response.ndc')
            ?? data_get($providerResponse, 'data.checkout.response.id')
            ?? data_get($providerResponse, 'checkout.ndc')
            ?? data_get($providerResponse, 'checkout.checkout_id')
            ?? data_get($providerResponse, 'checkout.checkoutId');

        if (! is_scalar($checkoutId) || trim((string) $checkoutId) === '') {
            return null;
        }

        $integrity = data_get($providerResponse, 'checkout.response.integrity')
            ?? data_get($providerResponse, 'data.checkout.response.integrity')
            ?? data_get($providerResponse, 'checkout.integrity');

        return [
            'checkout_id' => trim((string) $checkoutId),
            'integrity' => is_scalar($integrity) && trim((string) $integrity) !== ''
                ? trim((string) $integrity)
                : null,
        ];
    }

    protected function extractCheckoutUrl(array $providerResponse): ?string
    {
        foreach ([
            'checkout.hyperpay_url',
            'checkout.checkout_url',
            'checkout.redirect_url',
            'checkout.url',
            'data.hyperpay_url',
            'data.checkout_url',
            'data.redirect_url',
            'payment.hyperpay_url',
            'payment.checkout_url',
            'payment.redirect_url',
            'hyperpay_url',
            'checkout_url',
            'redirect_url',
        ] as $path) {
            $value = data_get($providerResponse, $path);
            if (is_string($value) && $this->isTransactionSpecificCheckoutUrl($value)) {
                return trim($value);
            }
        }

        $paymentId = data_get($providerResponse, 'checkout.payment_id')
            ?? data_get($providerResponse, 'checkout.paymentId')
            ?? data_get($providerResponse, 'checkout.payment.id')
            ?? data_get($providerResponse, 'payment.id')
            ?? data_get($providerResponse, 'payment_id')
            ?? data_get($providerResponse, 'paymentId');
        $ndc = data_get($providerResponse, 'checkout.ndc')
            ?? data_get($providerResponse, 'checkout.id')
            ?? data_get($providerResponse, 'checkout.payment.ndc')
            ?? data_get($providerResponse, 'payment.ndc')
            ?? data_get($providerResponse, 'ndc');
        $resourcePath = data_get($providerResponse, 'checkout.resource_path')
            ?? data_get($providerResponse, 'checkout.resourcePath')
            ?? data_get($providerResponse, 'checkout.payment.resource_path')
            ?? data_get($providerResponse, 'checkout.payment.resourcePath')
            ?? data_get($providerResponse, 'payment.resource_path')
            ?? data_get($providerResponse, 'payment.resourcePath')
            ?? data_get($providerResponse, 'resource_path')
            ?? data_get($providerResponse, 'resourcePath');

        if (! is_scalar($paymentId) || ! is_scalar($ndc) || ! is_scalar($resourcePath)) {
            return null;
        }

        $paymentId = trim((string) $paymentId);
        $ndc = trim((string) $ndc);
        $resourcePath = trim((string) $resourcePath);
        if ($paymentId === '' || $ndc === '' || $resourcePath === '') {
            return null;
        }

        $svpWeb = rtrim((string) config('svp.web_base_url'), '/');
        $confirmationUrl = $svpWeb.'/labor/confirmation?'.http_build_query([
            'paymentId' => $paymentId,
            'id' => $ndc,
            'resourcePath' => $resourcePath,
        ], '', '&', PHP_QUERY_RFC3986);

        return rtrim((string) config('svp.hyperpay_redirect_url', 'https://eu-prod.oppwa.com/v1/redirect.html'), '?').'?' . http_build_query([
            // HyperPay receives the transaction identifiers both as the
            // official SVP confirmation redirect and as top-level query
            // parameters. Do not reduce this to the generic HyperPay host.
            'redirectUrl' => $confirmationUrl,
            'paymentId' => $paymentId,
            'id' => $ndc,
            'resourcePath' => $resourcePath,
            'ndc' => $ndc,
            'target' => '_top',
            'method' => 'GET',
            'shopOrigin' => $svpWeb,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    protected function isTransactionSpecificCheckoutUrl(string $url): bool
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'], $parts['path'])) {
            return false;
        }

        $redirectBase = (string) config('svp.hyperpay_redirect_url', 'https://eu-prod.oppwa.com/v1/redirect.html');
        $redirectParts = parse_url($redirectBase);
        if (! is_array($redirectParts)) {
            return false;
        }

        $expectedHost = strtolower((string) ($redirectParts['host'] ?? ''));
        $expectedPath = rtrim((string) ($redirectParts['path'] ?? ''), '/');
        $actualHost = strtolower((string) $parts['host']);
        $actualPath = rtrim((string) $parts['path'], '/');
        if ($expectedHost === '' || $expectedPath === '' || $actualHost !== $expectedHost || $actualPath !== $expectedPath) {
            return false;
        }

        parse_str((string) ($parts['query'] ?? ''), $query);

        return is_string($query['redirectUrl'] ?? null)
            && trim($query['redirectUrl']) !== ''
            && is_string($query['ndc'] ?? null)
            && trim($query['ndc']) !== '';
    }

    /**
     * Release reserved balance, mark booking failed, notify user.
     *
     * @return array{booking: Booking, success: bool, error: string}
     */
    protected function handleBookingFailure(Booking $booking, ?BookingAttempt $attempt, mixed $response, ?string $error = null): array
    {
        $this->releasePortalBookingFee($booking);
        $booking->update(['booking_status' => 'failed']);
        $this->logEvent($booking, 'booking_failed', ['response' => $response]);

        if ($booking->user_id) {
            $this->notifications->send(
                $booking->user_id,
                'Booking failed',
                'Your booking could not be completed. Any reserved amount has been refunded.'
            );
        }

        $this->audit->log((int) $booking->agency_id, 'booking', ['booking_id' => $booking->id, 'action' => 'failed']);

        return ['booking' => $booking, 'success' => false, 'error' => $error ?? 'Booking failed.'];
    }

    /**
     * Return the canonical per-booking portal fee from Agency-specific settings,
     * falling back to the global admin setting and finally to the documented
     * zero-fee default. This is not the SVP reservation amount.
     */
    public function portalBookingFee(int $agencyId): float
    {
        $agencyValue = Setting::query()
            ->where('key', 'booking_price')
            ->where('agency_id', $agencyId)
            ->value('value');

        $globalValue = $agencyValue ?? Setting::query()
            ->where('key', 'booking_price')
            ->whereNull('agency_id')
            ->value('value');

        $configured = $globalValue ?? config('svp.portal_booking_fee', 0);
        $fee = is_numeric($configured) ? (float) $configured : 0.0;

        return max(0.0, round($fee, 2));
    }

    public function finalizePortalBookingFee(Booking $booking): void
    {
        $reference = $this->portalFeeReference($booking);
        $hold = $this->walletTransaction($booking, 'booking_hold', $reference);
        if (! $hold || $this->walletTransaction($booking, 'booking_debit', $reference)) {
            return;
        }

        $this->wallet->debit((int) $booking->agency_id, (float) $hold->amount, $reference, [
            'booking_id' => $booking->id,
            'purpose' => 'portal_booking_fee',
        ]);
    }

    public function releasePortalBookingFee(Booking $booking): void
    {
        $reference = $this->portalFeeReference($booking);
        $hold = $this->walletTransaction($booking, 'booking_hold', $reference);
        if (! $hold || $this->walletTransaction($booking, 'refund', $reference)) {
            return;
        }

        $this->wallet->releaseHold((int) $booking->agency_id, (float) $hold->amount, $reference, [
            'booking_id' => $booking->id,
            'purpose' => 'portal_booking_fee_release',
        ]);
    }

    protected function portalFeeReference(Booking $booking): string
    {
        return 'portal-booking-fee-'.$booking->id;
    }

    protected function walletTransaction(Booking $booking, string $type, string $reference): ?\App\Models\WalletTransaction
    {
        $wallet = $this->wallet->getWallet((int) $booking->agency_id);

        return $wallet->transactions()
            ->where('type', $type)
            ->where('reference', $reference)
            ->latest('id')
            ->first();
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
