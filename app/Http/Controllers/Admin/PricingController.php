<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;

class PricingController extends Controller
{
    public function index()
    {
        $settings = [
            'booking_price'     => Setting::where('key', 'booking_price')->first(),
            'service_fee'       => Setting::where('key', 'service_fee')->first(),
            'agency_commission' => Setting::where('key', 'agency_commission')->first(),
            'currency'          => Setting::where('key', 'currency')->first(),
        ];

        return view('admin.pricing.index', compact('settings'));
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'booking_price'     => ['required', 'numeric', 'min:0'],
            'service_fee'       => ['required', 'numeric', 'min:0'],
            'agency_commission' => ['required', 'numeric', 'min:0', 'max:100'],
            'currency'          => ['required', 'string', 'max:3'],
        ]);

        foreach ($data as $key => $value) {
            Setting::updateOrCreate(
                ['key' => $key],
                ['value' => (string) $value]
            );
        }

        return back()->with('success', 'Pricing settings updated.');
    }
}