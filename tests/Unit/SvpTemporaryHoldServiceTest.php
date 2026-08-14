<?php

namespace Tests\Unit;

use App\Services\SvpTemporaryHoldService;
use Illuminate\Http\Request;
use Tests\TestCase;

class SvpTemporaryHoldServiceTest extends TestCase
{
    public function test_matching_hold_is_consumed_once(): void
    {
        $request = $this->requestWithSession();
        $selection = $this->selection();
        $service = app(SvpTemporaryHoldService::class);

        $service->remember($request, $selection, 5143290, '14/08/2026 05:30');

        $hold = $service->consumeMatching($request, $selection + ['temporary_hold_id' => '5143290']);

        $this->assertSame('5143290', $hold['id']);
        $this->assertSame('14/08/2026 05:30', $hold['expires_at']);
        $this->assertNull($service->consumeMatching($request, $selection + ['temporary_hold_id' => '5143290']));
    }

    public function test_hold_cannot_be_used_for_a_different_selected_session(): void
    {
        $request = $this->requestWithSession();
        $selection = $this->selection();
        $service = app(SvpTemporaryHoldService::class);

        $service->remember($request, $selection, 'hold-2', null);

        $differentSelection = $selection + ['temporary_hold_id' => 'hold-2'];
        $differentSelection['exam_session_id'] = 'different-session';

        $this->assertNull($service->consumeMatching($request, $differentSelection));
        $this->assertSame(
            'hold-2',
            $service->consumeMatching($request, $selection + ['temporary_hold_id' => 'hold-2'])['id']
        );
    }

    private function requestWithSession(): Request
    {
        $request = Request::create('/temporary-hold-test', 'POST');
        $request->setLaravelSession(app('session.store'));
        $request->session()->start();

        return $request;
    }

    /**
     * @return array<string, string>
     */
    private function selection(): array
    {
        return [
            'occupation_id' => '2279',
            'category_id' => '159',
            'city' => 'Dhaka',
            'test_center_id' => '403',
            'exam_session_id' => '2',
            'exam_date' => '2026-08-18',
        ];
    }
}
