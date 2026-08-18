@extends('layouts.panel')

@section('title', 'Settings')
@section('page-title', 'Global Settings')

@section('content')
<div class="max-w-2xl">
    <div class="mb-6">
        <h2 class="text-xl font-bold text-slate-900">Global Settings</h2>
        <p class="text-sm text-slate-500 mt-0.5">Platform-wide configuration.</p>
    </div>

    @if ($errors->any())
        <div class="mb-6 px-4 py-3 bg-red-50 border border-red-200 text-red-700 rounded-xl text-sm">
            <p class="font-semibold mb-1">Please fix the following:</p>
            <ul class="list-disc list-inside space-y-0.5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6">
        <form method="POST" action="{{ route('admin.settings.update') }}" class="space-y-5">
            @csrf
            @method('PUT')

            <div>
                <label for="timezone" class="block text-sm font-medium text-slate-700 mb-1">Timezone</label>
                <input type="text" name="timezone" id="timezone" required
                    value="{{ old('timezone', $settings['timezone']?->value ?? 'UTC') }}"
                    placeholder="e.g. Asia/Riyadh"
                    class="w-full rounded-xl border-slate-200 text-sm font-mono focus:border-brand-500 focus:ring-brand-500">
                @error('timezone')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="currency" class="block text-sm font-medium text-slate-700 mb-1">Currency</label>
                <input type="text" name="currency" id="currency" required maxlength="3"
                    value="{{ old('currency', $settings['currency']?->value ?? 'BDT') }}"
                    class="w-full rounded-xl border-slate-200 text-sm font-mono focus:border-brand-500 focus:ring-brand-500">
                @error('currency')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
            </div>

            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" name="maintenance_mode" value="1"
                    @checked((bool) old('maintenance_mode', $settings['maintenance_mode']?->value ?? false))
                    class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                <span class="text-sm text-slate-700">Maintenance Mode</span>
            </label>

            <div class="pt-3 border-t border-slate-100">
                <button type="submit"
                    class="inline-flex items-center gap-2 px-5 py-2.5 bg-gradient-to-r from-indigo-500 to-fuchsia-500 hover:from-indigo-600 hover:to-fuchsia-600 text-white text-sm font-semibold rounded-xl shadow-lg shadow-indigo-500/20 transition">
                    Update Settings
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
