@extends('layouts.panel')

@section('title', 'Pricing')
@section('page-title', 'Pricing Settings')

@section('content')
<div class="max-w-2xl">
    <div class="mb-6">
        <h2 class="text-xl font-bold text-slate-900">Pricing Settings</h2>
        <p class="text-sm text-slate-500 mt-0.5">Configure booking pricing, fees and commissions.</p>
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
        <form method="POST" action="{{ route('admin.pricing.update') }}" class="space-y-5">
            @csrf
            @method('PUT')

            <div>
                <label for="booking_price" class="block text-sm font-medium text-slate-700 mb-1">Booking Price</label>
                <div class="relative">
                    <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 text-sm font-medium">BDT</span>
                    <input type="number" name="booking_price" id="booking_price" step="0.01" min="0" required
                        value="{{ old('booking_price', $settings['booking_price']?->value ?? 0) }}"
                        class="w-full rounded-xl pl-14 pr-4 py-3 border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
                </div>
                @error('booking_price')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="service_fee" class="block text-sm font-medium text-slate-700 mb-1">Service Fee</label>
                <div class="relative">
                    <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 text-sm font-medium">BDT</span>
                    <input type="number" name="service_fee" id="service_fee" step="0.01" min="0" required
                        value="{{ old('service_fee', $settings['service_fee']?->value ?? 0) }}"
                        class="w-full rounded-xl pl-14 pr-4 py-3 border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
                </div>
                @error('service_fee')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="agency_commission" class="block text-sm font-medium text-slate-700 mb-1">Agency Commission (%)</label>
                <input type="number" name="agency_commission" id="agency_commission" step="0.01" min="0" max="100" required
                    value="{{ old('agency_commission', $settings['agency_commission']?->value ?? 0) }}"
                    class="w-full rounded-xl border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
                @error('agency_commission')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="currency" class="block text-sm font-medium text-slate-700 mb-1">Currency</label>
                <input type="text" name="currency" id="currency" required maxlength="3"
                    value="{{ old('currency', $settings['currency']?->value ?? 'BDT') }}"
                    class="w-full rounded-xl border-slate-200 text-sm font-mono focus:border-brand-500 focus:ring-brand-500">
                @error('currency')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
            </div>

            <div class="pt-3 border-t border-slate-100">
                <button type="submit"
                    class="inline-flex items-center gap-2 px-5 py-2.5 bg-gradient-to-r from-indigo-500 to-fuchsia-500 hover:from-indigo-600 hover:to-fuchsia-600 text-white text-sm font-semibold rounded-xl shadow-lg shadow-indigo-500/20 transition">
                    Update Pricing
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
