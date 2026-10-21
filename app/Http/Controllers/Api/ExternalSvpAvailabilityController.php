<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CandidateSvpSession;
use App\Services\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

final class ExternalSvpAvailabilityController extends Controller
{
    public function __construct(private readonly BookingService $booking) {}

    public function occupations(Request $request): JsonResponse
    {
        return $this->booking->occupations($this->token($request));
    }

    public function cities(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category_id' => ['nullable', 'string', 'max:100'],
        ]);

        return $this->booking->cities($this->token($request), $data['category_id'] ?? null);
    }

    public function dates(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:120'],
            'exam_date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $params = [
            'category_id' => $data['category_id'],
            'city' => $data['city'],
        ];
        if (isset($data['exam_date'])) {
            $params['exam_date'] = $data['exam_date'];
        }

        return $this->booking->availableDates($this->token($request), null, $params);
    }

    public function centers(Request $request): JsonResponse
    {
        $data = $request->validate([
            'city' => ['nullable', 'string', 'max:120'],
            'category_id' => ['nullable', 'string', 'max:100'],
        ]);

        return $this->booking->testCenters(
            $this->token($request),
            $data['city'] ?? null,
            $data['category_id'] ?? null,
        );
    }

    public function sessions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:120'],
            'test_center_id' => ['required', 'string', 'max:100'],
            'exam_date' => ['required', 'date_format:Y-m-d'],
        ]);

        return $this->booking->sessionsForCenter($this->token($request), $data);
    }

    private function token(Request $request): string
    {
        $session = $request->attributes->get('candidate_svp_session');
        if (! $session instanceof CandidateSvpSession || ! $session->usable()) {
            throw new UnauthorizedHttpException('API key', 'The mapped SVP session has expired or is unavailable.');
        }

        return (string) $session->access_token;
    }
}
