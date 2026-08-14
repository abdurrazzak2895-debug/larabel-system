<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TestCenter;
use App\Services\BookingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Test Centers — the accurate local mirror of the SVP / Takamol test-center
 * dataset, kept in sync through the real SVP API.
 */
class TestCenterController extends Controller
{
    public function __construct(private BookingService $booking)
    {
    }

    /**
     * GET /admin/test-centers — list test centers as UI rows.
     */
    public function index(Request $request)
    {
        $query = TestCenter::query();

        if ($city = trim((string) $request->query('city'))) {
            $query->where('city', $city);
        }

        if ($q = trim((string) $request->query('q'))) {
            $query->where(function ($builder) use ($q) {
                $builder->where('name', 'like', "%{$q}%")
                    ->orWhere('svp_id', 'like', "%{$q}%");
            });
        }

        $testCenters = $query->orderBy('city')->orderBy('name')->paginate(25)->withQueryString();

        return view('admin.test-centers.index', [
            'testCenters' => $testCenters,
            'cities'      => TestCenter::query()->distinct()->orderBy('city')->pluck('city'),
            'filter'      => (string) $request->query('city'),
            'search'      => (string) $request->query('q'),
        ]);
    }

    /**
     * POST /admin/test-centers/sync — call the real SVP / Takamol API and
     * upsert the returned test centers into the local table.
     */
    public function sync(Request $request)
    {
        $token = $request->session()->get('svp_token');

        if (! is_string($token) || $token === '') {
            return back()->with('error', 'No SVP session found. Please sign in with your SVP account first.');
        }

        try {
            $response = $this->booking->testCenters($token);
            $data = $response->getData(true);

            $centers = data_get($data, 'data.test_centers', data_get($data, 'data', data_get($data, 'test_centers', [])));

            if (! is_array($centers) || count($centers) === 0) {
                return back()->with('error', 'The SVP API returned no test centers for the current session.');
            }

            $created = 0;
            $updated = 0;

            foreach ($centers as $center) {
                $svpId = (string) ($center['id'] ?? $center['svp_id'] ?? '');
                $name  = (string) ($center['name'] ?? '');
                $city  = (string) ($center['city'] ?? '');

                if ($svpId === '' || $name === '') {
                    continue;
                }

                $testCenter = TestCenter::updateOrCreate(
                    ['svp_id' => $svpId],
                    [
                        'name'         => $name,
                        'city'         => $city,
                        'country_code' => (string) ($center['country_code'] ?? 'BD'),
                    ]
                );

                if ($testCenter->wasRecentlyCreated) {
                    $created++;
                } else {
                    $updated++;
                }
            }

            return back()->with('success', "SVP API sync complete — {$created} new, {$updated} updated.");
        } catch (\Throwable $e) {
            Log::error('SVP test-centers sync failed', ['error' => $e->getMessage()]);

            return back()->with('error', 'SVP sync failed: '.$e->getMessage());
        }
    }
}
