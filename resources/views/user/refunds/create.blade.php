@extends('layouts.user')

@section('title', 'Request Refund')
@section('page-title', 'Request Refund')

@section('content')
<div class="space-y-6">
    {{-- ===================== Page header ===================== --}}
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Request Refund</h1>
            <p class="text-sm text-slate-500 mt-1">Submit a refund request for one of your bookings.</p>
        </div>
        <a href="{{ route('user.refunds.index') }}" class="inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl border border-slate-200 bg-white text-slate-700 text-sm font-medium hover:bg-slate-50 hover:border-slate-300 transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            Back to Refunds
        </a>
    </div>

    {{-- ===================== Refund form ===================== --}}
    <div class="max-w-2xl bg-white rounded-2xl border border-slate-200 shadow-sm p-6 sm:p-8">
        <h2 class="text-lg font-bold text-slate-900 mb-1">New Refund Request</h2>
        <p class="text-sm text-slate-500 mb-6">Select the booking and enter the refund amount and reason.</p>

        <form method="POST" action="{{ route('user.refunds.store') }}" class="space-y-5">
            @csrf
            <div>
                <label for="booking_id" class="block text-sm font-medium text-slate-700 mb-1.5">Booking</label>
                <select name="booking_id" id="booking_id" required
                        class="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-500/30 focus:border-indigo-400 transition">
                    <option value="">Select a booking</option>
                    @foreach ($bookings as $booking)
                        <option value="{{ $booking->id }}" {{ old('booking_id', $selectedBooking) == $booking->id ? 'selected' : '' }}>
                            #{{ $booking->id }} · {{ $booking->booking_reference ?? 'N/A' }} · {{ ucfirst($booking->booking_status) }}
                        </option>
                    @endforeach
                </select>
                @error('booking_id') <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="amount" class="block text-sm font-medium text-slate-700 mb-1.5">Refund Amount (BDT)</label>
                <input type="number" name="amount" id="amount" step="0.01" min="1" value="{{ old('amount') }}" required
                       class="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-sm text-slate-700 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/30 focus:border-indigo-400 transition"
                       placeholder="Enter refund amount">
                @error('amount') <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="reason" class="block text-sm font-medium text-slate-700 mb-1.5">Reason</label>
                <textarea name="reason" id="reason" rows="4" maxlength="1000" required
                          class="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-sm text-slate-700 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/30 focus:border-indigo-400 transition"
                          placeholder="Explain why you are requesting a refund...">{{ old('reason') }}</textarea>
                @error('reason') <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="inline-flex items-center justify-center gap-2 px-5 py-2.5 bg-gradient-to-r from-indigo-500 to-fuchsia-500 hover:from-indigo-600 hover:to-fuchsia-600 text-white text-sm font-semibold rounded-xl shadow-lg shadow-indigo-500/25 transition">
                    Submit Refund Request
                </button>
                <a href="{{ route('user.refunds.index') }}" class="px-5 py-2.5 rounded-xl border border-slate-200 bg-white text-slate-700 text-sm font-medium hover:bg-slate-50 hover:border-slate-300 transition">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection
