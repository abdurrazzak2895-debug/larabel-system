@extends('layouts.panel')

@section('title', 'New Refund')
@section('page-title', 'Request Refund')

@section('content')
<div class="max-w-2xl">
    <div class="flex items-center gap-3 mb-6">
        <a href="{{ route('agency.refunds.index') }}" class="text-slate-400 hover:text-slate-600 transition">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        </a>
        <div>
            <h2 class="text-xl font-bold text-slate-900">Request Refund</h2>
            <p class="text-sm text-slate-500 mt-0.5">Submit a refund for a booking. An admin will review the request.</p>
        </div>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6">
        <form method="POST" action="{{ route('agency.refunds.store') }}" class="space-y-5">
            @csrf

            <div>
                <label for="booking_id" class="block text-sm font-medium text-slate-700 mb-1">Booking</label>
                <select name="booking_id" id="booking_id" required
                    class="w-full rounded-xl border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
                    <option value="">Select a booking…</option>
                    @foreach ($bookings as $booking)
                        <option value="{{ $booking->id }}" @selected(old('booking_id') == $booking->id)>
                            {{ $booking->booking_reference }} — {{ $booking->credential?->full_name ?? 'Candidate' }} ({{ $booking->booking_status }})
                        </option>
                    @endforeach
                </select>
                @error('booking_id')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                @if ($bookings->isEmpty())
                    <p class="text-xs text-amber-600 mt-2">No bookings found. Create a booking first.</p>
                @endif
            </div>

            <div>
                <label for="amount" class="block text-sm font-medium text-slate-700 mb-1">Amount <span class="text-slate-400">(SAR)</span></label>
                <div class="relative">
                    <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 text-sm font-medium">SAR</span>
                    <input type="number" name="amount" id="amount" step="0.01" min="1" required value="{{ old('amount') }}"
                        placeholder="0.00"
                        class="w-full rounded-xl pl-14 pr-4 py-3 border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
                </div>
                @error('amount')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="reason" class="block text-sm font-medium text-slate-700 mb-1">Reason</label>
                <textarea name="reason" id="reason" rows="4" maxlength="1000" required placeholder="Explain why this booking needs a refund…"
                    class="w-full rounded-xl border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">{{ old('reason') }}</textarea>
                @error('reason')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
            </div>

            <div class="pt-2 flex items-center gap-3">
                <button type="submit"
                    class="inline-flex items-center gap-2 px-5 py-2.5 bg-gradient-to-r from-indigo-500 to-fuchsia-500 hover:from-indigo-600 hover:to-fuchsia-600 text-white text-sm font-semibold rounded-xl shadow-lg shadow-indigo-500/20 transition">
                    Submit Refund Request
                </button>
                <a href="{{ route('agency.refunds.index') }}"
                   class="inline-flex items-center gap-2 px-5 py-2.5 bg-white border border-slate-200 text-slate-700 text-sm font-medium rounded-xl hover:bg-slate-50 transition">
                    Cancel
                </a>
            </div>
        </form>
    </div>
</div>
@endsection
