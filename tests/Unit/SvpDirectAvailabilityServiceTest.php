<?php

namespace Tests\Unit;

use App\Models\TestCenter;
use App\Services\BookingService;
use App\Services\SvpDirectAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SvpDirectAvailabilityServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_fallback_returns_all_real_sessions_for_each_configured_center_and_filters_foreign_rows(): void
    {
        TestCenter::query()->create([
            'svp_id' => '171',
            'name' => 'Jashore Technical Training Centre',
            'city' => 'Khulna',
            'country_code' => 'BD',
        ]);
        TestCenter::query()->create([
            'svp_id' => '181',
            'name' => 'Narail Technical Training Centre',
            'city' => 'Khulna',
            'country_code' => 'BD',
        ]);

        $requestedSessions = [];
        $booking = $this->createMock(BookingService::class);
        $booking->method('sessionsForCenter')->willReturnCallback(
            function (string $token, array $params) use (&$requestedSessions) {
                $centerId = (string) ($params['test_center_id'] ?? '');
                $requestedSessions[$centerId] = [];
                $sessionIds = $centerId === '171'
                    ? ['session-171-1', 'session-171-2', 'session-171-3', 'session-171-4']
                    : ['session-181-1'];

                $requestedSessions[$centerId] = $sessionIds;
                $sessions = array_map(
                    static fn (string $id): array => [
                        'id' => $id,
                        'exam_date' => '2026-08-31',
                        'test_center_id' => $centerId,
                        'available_seats' => 1,
                    ],
                    $sessionIds,
                );

                if ($centerId === '171') {
                    $sessions[] = [
                        'id' => 'foreign-session-181',
                        'exam_date' => '2026-08-31',
                        'test_center_id' => '181',
                        'available_seats' => 1,
                    ];
                }

                return response()->json([
                    'success' => true,
                    'data' => [
                        'sessions' => $sessions,
                        'exam_sessions' => $sessions,
                    ],
                ]);
            },
        );

        $result = (new SvpDirectAvailabilityService($booking))->centersForDate('candidate-token', [
            'city' => 'Khulna',
            'category_id' => '159',
            'date' => '2026-08-31',
        ]);

        $jashore = collect($result['centers'])->firstWhere('test_center_id', '171');
        $narail = collect($result['centers'])->firstWhere('test_center_id', '181');

        $this->assertTrue($result['success']);
        $this->assertTrue($result['fallback']);
        $this->assertSame('candidate_authenticated_sessions', $result['availability_source']);
        $this->assertIsArray($jashore);
        $this->assertIsArray($narail);
        $this->assertSame(4, $jashore['session_count']);
        $this->assertSame(1, $narail['session_count']);
        $this->assertSame([
            'session-171-1',
            'session-171-2',
            'session-171-3',
            'session-171-4',
        ], collect($result['centers'])->where('test_center_id', '171')->pluck('exam_session_id')->values()->all());
        $this->assertSame(1, collect($result['centers'])->where('test_center_id', '171')->pluck('available_seats')->unique()->first());
        $this->assertSame([
            'session-171-1',
            'session-171-2',
            'session-171-3',
            'session-171-4',
        ], $requestedSessions['171']);
    }
}
