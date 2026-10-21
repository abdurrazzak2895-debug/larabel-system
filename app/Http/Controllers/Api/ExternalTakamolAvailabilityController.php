<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\T2Hub\T2HubProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ExternalTakamolAvailabilityController extends Controller
{
    public function __construct(private readonly T2HubProvider $provider) {}

    public function occupations(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        return $this->respond($request, fn (): array => [
            'occupations' => $this->provider->occupations($data['search'] ?? null),
        ]);
    }

    public function cities(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'string', 'max:100'],
        ]);

        return $this->respond($request, fn (): array => [
            'category_id' => $data['category_id'],
            'cities' => $this->provider->cities($data['category_id']),
        ]);
    }

    public function dates(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:120'],
        ]);

        return $this->respond($request, fn (): array => $this->provider->availableDates(
            $data['category_id'],
            $data['city'],
        ));
    }

    public function centers(Request $request): JsonResponse
    {
        $data = $request->validate([
            'city' => ['nullable', 'string', 'max:120'],
        ]);

        return $this->respond($request, fn (): array => [
            'sites' => filled($data['city'] ?? null)
                ? $this->provider->testCenters($data['city'])
                : $this->provider->allTestCenters(),
        ]);
    }

    public function sessions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:120'],
            'exam_date' => ['required', 'date_format:Y-m-d'],
            'test_center_id' => ['nullable', 'string', 'max:100'],
        ]);

        return $this->respond($request, function () use ($data): array {
            $result = $this->provider->sessions(
                $data['category_id'],
                $data['city'],
                $data['exam_date'],
                $data['test_center_id'] ?? null,
            );
            $sessions = $result['sessions'];

            if (filled($data['test_center_id'] ?? null)) {
                $centerId = (string) $data['test_center_id'];
                $sessions = array_values(array_filter($sessions, static function (array $session) use ($centerId): bool {
                    $sessionCenterId = (string) (
                        $session['site_id']
                        ?? $session['test_center_id']
                        ?? $session['test_center']['id']
                        ?? ''
                    );

                    return $sessionCenterId === $centerId;
                }));
            }

            return [
                'sessions' => $sessions,
                'exam_sessions' => $sessions,
                'sites' => $result['sites'],
            ];
        });
    }

    private function respond(Request $request, callable $callback): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data' => $callback(),
            ]);
        } catch (Throwable $exception) {
            Log::warning('External Takamol availability lookup failed', [
                'endpoint' => $request->path(),
                'exception' => $exception::class,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Takamol availability is temporarily unavailable.',
            ], 502);
        }
    }
}
