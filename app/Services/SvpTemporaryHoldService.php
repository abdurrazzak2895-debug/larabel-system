<?php

namespace App\Services;

use Illuminate\Http\Request;

/**
 * Keeps short-lived SVP temporary-seat holds bound to the authenticated
 * browser session and to the exact booking-selection context that created them.
 */
class SvpTemporaryHoldService
{
    private const SESSION_KEY = 'svp_temporary_holds';

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
     * @return array<string, array<string, mixed>>
     */
    private function holds(Request $request): array
    {
        $holds = $request->session()->get(self::SESSION_KEY, []);

        return is_array($holds) ? $holds : [];
    }
}
