<?php

namespace App\Console\Commands;

use App\Services\Providers\TakamolProvider;
use App\Services\SvpOtp\OtpAutoVerifier;
use Illuminate\Console\Command;

/**
 * Live hold + booking-confirm test against the real SVP tenant.
 *
 * Walks the same chain the booking page uses, then creates a reservation
 * (the hold), validates it the way the confirm step does and finally cancels it
 * so no real seat is left locked.
 */
class SvpLiveBookingTestCommand extends Command
{
    protected $signature = 'svp:live-booking-test
        {--email=mdforidmmmmm@dakbox.net}
        {--password= : SVP password}
        {--city=Dhaka}
        {--keep : Do not cancel the reservation at the end}';

    protected $description = 'Live-test the SVP hold (create reservation) and booking confirm path';

    public function handle(OtpAutoVerifier $verifier, TakamolProvider $provider): int
    {
        $email = (string) $this->option('email');
        $password = (string) ($this->option('password') ?: config('svp.otp_test_password'));

        if ($password === '') {
            $this->error('SVP password missing (SVP_OTP_TEST_PASSWORD).');

            return self::FAILURE;
        }

        $this->line('1) SVP login with automatic email OTP');
        $login = $verifier->login($email, $password);

        $token = $login['body']['access_payload']['access'] ?? null;

        if (! $token) {
            $this->error('No bearer token: ' . mb_substr(json_encode($login['body']) ?: '', 0, 200));

            return self::FAILURE;
        }

        $this->info(sprintf('   token acquired (auto OTP via %s)', $login['otp_source'] ?: 'manual'));
        $api = $provider->withToken($token);

        $this->line('2) Occupations');
        $occupations = $this->payload($api->occupationsSearch('barber', 1, 20));
        $occupation = $this->firstRow($occupations, ['occupations', 'data']);

        if (! is_array($occupation)) {
            $this->error('   no occupation returned: ' . mb_substr(json_encode($occupations) ?: '', 0, 200));

            return self::FAILURE;
        }

        $occupationId = $occupation['id'] ?? null;
        $this->line(sprintf('   %s (id %s)', $occupation['name'] ?? $occupation['english_name'] ?? '?', $occupationId));

        $this->line('3) Categories');
        $categories = $this->payload($api->categoriesForOccupation($occupationId));
        $category = $this->firstRow($categories, ['categories', 'data']);
        $categoryId = is_array($category) ? ($category['id'] ?? null) : null;
        $this->line(sprintf('   %s (id %s)', is_array($category) ? ($category['name'] ?? $category['title'] ?? '?') : '?', $categoryId));

        $city = (string) $this->option('city');

        $this->line('4) Available dates in ' . $city);
        $dates = $this->payload($api->availableDates(null, ['category_id' => $categoryId, 'city' => $city, 'occupation_id' => $occupationId]));
        $date = $this->firstDate($dates);

        if ($date === null) {
            $this->error('   no available date: ' . mb_substr(json_encode($dates) ?: '', 0, 240));

            return self::FAILURE;
        }

        $this->line('   first date: ' . $date);

        $this->line('5) Test centres + sessions for that date');
        $session = null;
        $centerId = null;

        foreach ((array) config('svp.dhaka_test_centers', []) as $center) {
            $candidate = $this->payload($api->examSessionsForCenter([
                'category_id' => $categoryId,
                'city' => $city,
                'test_center_id' => $center['id'],
                'exam_date' => $date,
                'occupation_id' => $occupationId,
            ]));

            $row = $this->firstRow($candidate, ['sessions', 'exam_sessions', 'data']);

            if (is_array($row)) {
                $session = $row;
                $centerId = $center['id'];
                $this->line(sprintf('   centre %s -> session %s', $centerId, $row['id'] ?? '?'));
                break;
            }
        }

        if ($session === null) {
            $this->error('   no session available for ' . $date);

            return self::FAILURE;
        }

        $this->line('6) HOLD — POST /individual_labor_space/exam_reservations');
        $hold = $this->payload($api->createReservation([
            'exam_session_id' => (string) ($session['id'] ?? ''),
            'occupation_id' => $occupationId,
            'language_code' => (string) config('svp.default_language_code', 'LOABB'),
            'methodology' => (string) config('svp.default_methodology', 'in_person'),
            'site_id' => (string) $centerId,
            'site_city' => $city,
            'country_id' => (int) config('svp.country_id', 78),
            'accept_declaration' => true,
            'info_confirmation' => true,
            'practical_confirmation' => true,
        ]));

        $reservationId = $this->firstId($hold);
        $this->line('   hold response: ' . mb_substr(json_encode($hold) ?: '', 0, 320));

        $this->line('7) CONFIRM validation — GET /individual_labor_space/exam_reservations/validate');
        $validation = $this->payload($api->validateReservation());
        $this->line('   validate response: ' . mb_substr(json_encode($validation) ?: '', 0, 320));

        if ($reservationId !== null) {
            $this->line('8) Reservation details for ' . $reservationId);
            $details = $this->payload($api->reservationDetails($reservationId));
            $this->line('   details: ' . mb_substr(json_encode($details) ?: '', 0, 320));
        }

        if ($reservationId !== null && ! $this->option('keep')) {
            $this->line('9) Cleanup — cancelling the test reservation');
            $cancelled = $this->payload($api->cancelReservation($reservationId));
            $this->line('   cancel response: ' . mb_substr(json_encode($cancelled) ?: '', 0, 200));
        }

        $this->info('Live hold + confirm test finished.');

        return self::SUCCESS;
    }

    private function payload(mixed $response): array
    {
        if ($response instanceof \Illuminate\Http\JsonResponse) {
            $data = $response->getData(true);

            return is_array($data) ? $data : [];
        }

        return is_array($response) ? $response : [];
    }

    /**
     * Responses wrap their list in a resource-specific key, so look for the
     * known keys first and otherwise fall back to the first array element.
     */
    /**
     * SVP wraps lists in resource keys (`occupations`, `sessions`, `data`) that
     * can be nested one level deep, so walk the payload until a list is found.
     */
    private function firstRow(mixed $payload, array $keys): ?array
    {
        if (! is_array($payload)) {
            return null;
        }

        foreach ($keys as $key) {
            $rows = $payload[$key] ?? null;

            if (! is_array($rows) || $rows === []) {
                continue;
            }

            if (array_is_list($rows)) {
                $first = reset($rows);

                if (is_array($first)) {
                    return $first;
                }

                continue;
            }

            $nested = $this->firstRow($rows, $keys);

            if ($nested !== null) {
                return $nested;
            }
        }

        return null;
    }

    private function firstDate(array $dates): ?string
    {
        foreach ($dates['dates'] ?? $dates['available_dates'] ?? $dates['data'] ?? $dates as $row) {
            if (is_array($row)) {
                foreach ([
                    'date',
                    'exam_date',
                    'test_date',
                    'start_date_in_browser_time_zone',
                    'start_date_in_tc_time_zone',
                ] as $key) {
                    if (! empty($row[$key])) {
                        return (string) $row[$key];
                    }
                }
            } elseif (is_string($row) && $row !== '') {
                return $row;
            }
        }

        return null;
    }

    private function firstId(array $payload): ?string
    {
        foreach ($payload['reservation'] ?? $payload['data'] ?? $payload as $row) {
            if (is_array($row)) {
                foreach (['id', 'reservation_id', 'reservationId', 'hold_id'] as $key) {
                    if (! empty($row[$key])) {
                        return (string) $row[$key];
                    }
                }
            }
        }

        return null;
    }
}
