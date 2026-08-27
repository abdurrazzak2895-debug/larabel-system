<?php

namespace App\Services;

use Illuminate\Http\Request;

/**
 * Keeps short-lived SVP temporary-seat holds and the selected live-session
 * lookup snapshot bound to the authenticated browser session.
 */
class SvpTemporaryHoldService
{
    private const SESSION_KEY = 'svp_temporary_holds';

    private const SESSION_LOOKUP_KEY = 'svp_session_lookups';

    /**
     * Remember a successfully created upstream hold for later final-booking validation.
     *
     * @param array<string, mixed> $selection
     * @return array<string, mixed>
     */
    public function remember(Request $request, array $selection, string|int $holdId, ?string $expiresAt): array
    {
        $holds = $this->holds($request);
        $id = (string) $holdId;

        $hold = [
            'id' => $id,
            'expires_at' => $expiresAt,
            'occupation_id' => (string) ($selection['occupation_id'] ?? ''),
            'category_id' => (string) ($selection['category_id'] ?? ''),
            'city' => (string) ($selection['city'] ?? ''),
            'test_center_id' => (string) ($selection['test_center_id'] ?? ''),
            'test_center_name' => $selection['test_center_name'] ?? null,
            'test_center_time' => $this->normalizeTime($selection['test_center_time'] ?? ''),
            'exam_session_id' => (string) ($selection['exam_session_id'] ?? ''),
            'exam_date' => (string) ($selection['exam_date'] ?? ''),
        ];

        $holds[$id] = $hold;

        // Retain only a small recent set in case a user creates a replacement hold.
        if (count($holds) > 10) {
            $holds = array_slice($holds, -10, null, true);
        }

        $request->session()->put(self::SESSION_KEY, $holds);

        return $hold;
    }

    /**
     * Remember the normalized live session list used to populate the wizard.
     *
     * SVP session IDs can rotate between two list requests. Holding the exact
     * list returned to this browser lets the hold endpoint validate the selected
     * session/date without replacing it with a different upstream list. An empty
     * response intentionally replaces the previous snapshot so stale sessions
     * cannot survive a date-specific availability miss.
     *
     * @param array<string, mixed> $context
     * @param mixed $payload
     */
    public function rememberSessionLookup(Request $request, array $context, mixed $payload): void
    {
        $sessions = $this->extractSessions($payload);

        $lookups = $this->sessionLookups($request);
        $key = $this->lookupKey($context);
        $lookups[$key] = [
            'context' => [
                'category_id' => (string) ($context['category_id'] ?? ''),
                'city' => (string) ($context['city'] ?? ''),
                'test_center_id' => (string) ($context['test_center_id'] ?? ''),
            ],
            'sessions' => $sessions,
            'stored_at' => now()->timestamp,
        ];

        // Keep only a small number of recent center lookups per browser session.
        if (count($lookups) > 10) {
            $lookups = array_slice($lookups, -10, null, true);
        }

        $request->session()->put(self::SESSION_LOOKUP_KEY, $lookups);
    }

    /**
     * Resolve the selected session from the exact center-scoped list returned
     * to this browser. If the upstream session carries a different center,
     * choose the next dated session from this same center-scoped snapshot.
     * Never fall back to another center.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>|null
     */
    public function resolveCenterSession(Request $request, array $context, string $sessionId, string $preferredDate): ?array
    {
        $lookup = $this->sessionLookups($request)[$this->lookupKey($context)] ?? null;
        if (! is_array($lookup) || ! is_array($lookup['sessions'] ?? null)) {
            return null;
        }

        $selected = null;
        foreach ($lookup['sessions'] as $session) {
            if (is_array($session) && (string) ($session['id'] ?? '') === $sessionId) {
                $selected = $session;
                break;
            }
        }

        $requestedCenter = (string) ($context['test_center_id'] ?? '');
        $selectedCenter = $this->sessionCenterId($selected);
        if ($selected !== null) {
            // An explicit center must match the selected center exactly. A
            // centerless list row is allowed through here because the hold
            // controller performs the authoritative session-by-ID lookup
            // immediately before creating the upstream hold.
            return ($selectedCenter === '' || $selectedCenter === $requestedCenter)
                ? $selected
                : null;
        }

        // PACC may rotate opaque session IDs between lookup and hold. Only
        // when the submitted ID is no longer present may we resolve the
        // earliest date on or after the requested date from this same,
        // center-scoped snapshot. Never accept a center-less or other-center
        // candidate as a fallback.
        $candidates = array_values(array_filter($lookup['sessions'], function ($session) use ($requestedCenter, $preferredDate): bool {
            if (! is_array($session)) {
                return false;
            }
            $center = $this->sessionCenterId($session);
            $date = $this->sessionDate($session);
            return $center !== ''
                && $center === $requestedCenter
                && $date !== null
                && $date >= $preferredDate;
        }));

        usort($candidates, function (array $left, array $right): int {
            return strcmp($this->sessionDate($left) ?? '9999-99-99', $this->sessionDate($right) ?? '9999-99-99');
        });

        return $candidates[0] ?? null;
    }

    /**
     * Resolve a selected session without applying a fallback.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>|null
     */
    public function findRememberedSession(Request $request, array $context, string $sessionId): ?array
    {
        return $this->resolveCenterSession($request, $context, $sessionId, '0000-00-00');
    }

    private function sessionCenterId(?array $session): string
    {
        $center = is_array($session['test_center'] ?? null) ? $session['test_center'] : [];
        return (string) ($session['test_center_id'] ?? $session['site_id'] ?? $center['id'] ?? '');
    }

    private function sessionDate(array $session): ?string
    {
        foreach (['exam_date', 'test_date', 'date', 'start_date_in_browser_time_zone', 'start_date_in_tc_time_zone'] as $key) {
            $value = $session[$key] ?? null;
            if (is_string($value) && preg_match('/^\\d{4}-\\d{2}-\\d{2}/', $value) === 1) {
                return substr($value, 0, 10);
            }
        }

        return null;
    }


    /**
     * Consume a hold only when it was created in this session for the exact
     * occupation/category/city/center/session/date submitted in the booking.
     *
     * @param array<string, mixed> $selection
     * @return array<string, mixed>|null
     */
    public function consumeMatching(Request $request, array $selection): ?array
    {
        $holdId = trim((string) ($selection['temporary_hold_id'] ?? ''));
        if ($holdId === '') {
            return null;
        }

        $holds = $this->holds($request);
        $hold = $holds[$holdId] ?? null;

        if (! is_array($hold)) {
            return null;
        }

        foreach ([
            'occupation_id',
            'category_id',
            'city',
            'test_center_id',
            'test_center_time',
            'exam_session_id',
            'exam_date',
        ] as $field) {
            $heldValue = $hold[$field] ?? '';
            $selectedValue = $selection[$field] ?? '';
            if ($field === 'test_center_time') {
                $heldValue = $this->normalizeTime($heldValue);
                $selectedValue = $this->normalizeTime($selectedValue);
            }
            if ((string) $heldValue !== (string) $selectedValue) {
                return null;
            }
        }

        // One hold is accepted for one booking attempt only. If the SVP
        // reservation fails, the user can create a fresh hold from the wizard.
        unset($holds[$holdId]);
        $request->session()->put(self::SESSION_KEY, $holds);

        return $hold;
    }

    /**
     * @param mixed $payload
     * @return array<int, array<string, mixed>>
     */
    private function extractSessions(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        $sessions = data_get($payload, 'data.sessions')
            ?? data_get($payload, 'data.exam_sessions')
            ?? ($payload['sessions'] ?? null)
            ?? ($payload['exam_sessions'] ?? null)
            ?? [];

        if (! is_array($sessions)) {
            return [];
        }

        return array_values(array_filter($sessions, static fn ($session): bool => is_array($session)));
    }

    /**
     * @param array<string, mixed> $context
     */
    private function lookupKey(array $context): string
    {
        return hash('sha256', implode('|', [
            (string) ($context['category_id'] ?? ''),
            (string) ($context['city'] ?? ''),
            (string) ($context['test_center_id'] ?? ''),
        ]));
    }

    private function normalizeTime(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/^\\d{4}-\\d{2}-\\d{2}[T ]/', '', $value) ?? $value;
        $value = preg_replace('/(?:Z|[+-]\\d{2}:?\\d{2})$/', '', $value) ?? $value;
        if (preg_match('/^(\\d{1,2}):(\\d{2})(?::\\d{2})?\\s*(AM|PM)?$/i', trim($value), $matches) !== 1) {
            return '';
        }

        $hours = (int) $matches[1];
        $minutes = (int) $matches[2];
        $meridiem = strtoupper($matches[3] ?? '');
        if ($minutes > 59 || ($meridiem !== '' && ($hours < 1 || $hours > 12)) || ($meridiem === '' && $hours > 23)) {
            return '';
        }
        if ($meridiem === 'PM' && $hours < 12) {
            $hours += 12;
        } elseif ($meridiem === 'AM' && $hours === 12) {
            $hours = 0;
        }

        return sprintf('%02d:%02d', $hours, $minutes);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function holds(Request $request): array
    {
        $holds = $request->session()->get(self::SESSION_KEY, []);

        return is_array($holds) ? $holds : [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function sessionLookups(Request $request): array
    {
        $lookups = $request->session()->get(self::SESSION_LOOKUP_KEY, []);

        return is_array($lookups) ? $lookups : [];
    }
}
