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
     * session/date without replacing it with a different upstream list.
     *
     * @param array<string, mixed> $context
     * @param mixed $payload
     */
    public function rememberSessionLookup(Request $request, array $context, mixed $payload): void
    {
        $sessions = $this->extractSessions($payload);
        if ($sessions === []) {
            return;
        }

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
     * Resolve a selected session from the exact list previously returned to the browser.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>|null
     */
    public function findRememberedSession(Request $request, array $context, string $sessionId): ?array
    {
        $lookup = $this->sessionLookups($request)[$this->lookupKey($context)] ?? null;
        if (! is_array($lookup) || ! is_array($lookup['sessions'] ?? null)) {
            return null;
        }

        foreach ($lookup['sessions'] as $session) {
            if (is_array($session) && (string) ($session['id'] ?? '') === $sessionId) {
                return $session;
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
            'exam_session_id',
            'exam_date',
        ] as $field) {
            if ((string) ($hold[$field] ?? '') !== (string) ($selection[$field] ?? '')) {
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
