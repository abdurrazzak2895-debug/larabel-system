<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function index()
    {
        $settings = Setting::all()->keyBy('key');

        return view('admin.settings.index', compact('settings'));
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'booking_config'   => ['nullable', 'array'],
            'retry_config'     => ['nullable', 'array'],
            'queue_config'     => ['nullable', 'array'],
            'redis_config'     => ['nullable', 'array'],
            'maintenance_mode' => ['boolean'],
            'timezone'         => ['required', 'string'],
            'currency'         => ['required', 'string'],
        ]);

        foreach ($data as $key => $value) {
            Setting::updateOrCreate(
                ['key' => $key],
                ['value' => is_array($value) ? json_encode($value) : (string) $value]
            );
        }

        return back()->with('success', 'Global settings updated.');
    }
}