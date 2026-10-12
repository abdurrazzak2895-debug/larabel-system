@extends('layouts.user')

@section('title', 'Request Refund')
@section('page-title', 'Request Refund')

@section('content')
<div class="max-w-3xl space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Request Refund</h1>
            <p class="text-sm text-slate-500 mt-1">Submit a manual refund request for a failed or cancelled booking.</p>
        </div>
        <a href="{{ route('user.refunds.index') }}" class="inline-flex items-center justify-center px-4 py-2.5 rounded-xl border border-slate-200 bg-white text-slate-700 text-sm font-semibold hover:bg-slate-50 transition">Back to Refunds</a>
    </div>

    @if (session('error'))
        <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ session('error') }}</div>
    @endif
    @if ($errors->any())
        <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            <ul class="list-disc list-inside space-y-1">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-900">
        <p class="font-semibold">Manual review required</p>
        <p class="mt-1 text-amber-800">Your request will remain pending until an administrator reviews it. No wallet credit is issued automatically.</p>
    </div>

    <form method="POST" action="{{ route('user.refunds.store') }}" class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 space-y-5">
        @csrf
        <div>
            <label for="booking_id" class="block text-sm font-semibold text-slate-700">Failed or cancelled booking</label>
            <select id="booking_id" name="booking_id" required class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-3 py-3 text-sm text-slate-800 focus:border-indigo-500 focus:ring-indigo-500">
                <option value="">Select a booking</option>
                @foreach ($eligibleBookings as $booking)
                    <option value="{{ $booking->id }}" @selected((int) old('booking_id', $selectedBookingId) === (int) $booking->id)>
                        {{ $booking->booking_reference ?: '#'.$booking->id }} — {{ ucfirst($booking->booking_status) }} — {{ number_format((float) $booking->portal_booking_fee, 2) }} BDT
                    </option>
                @endforeach
            </select>
            @error('booking_id') <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p> @enderror
            @if ($eligibleBookings->isEmpty())
                <p class="mt-2 text-xs text-slate-500">No failed or cancelled booking with an available portal fee is currently eligible.</p>
            @endif
        </div>

        <div>
            <label for="reason" class="block text-sm font-semibold text-slate-700">Reason for refund request</label>
            <textarea id="reason" name="reason" rows="5" required minlength="10" maxlength="1000" class="mt-2 w-full rounded-xl border border-slate-300 px-3 py-3 text-sm text-slate-800 placeholder-slate-400 focus:border-indigo-500 focus:ring-indigo-500" placeholder="Explain why you are requesting a refund...">{{ old('reason') }}</textarea>
            @error('reason') <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p> @enderror
            <p class="mt-1 text-xs text-slate-400">Minimum 10 characters. The administrator will see this explanation.</p>
        </div>

        <div class="flex flex-col-reverse sm:flex-row sm:items-center sm:justify-end gap-3">
            <a href="{{ route('user.refunds.index') }}" class="inline-flex items-center justify-center px-4 py-2.5 rounded-xl border border-slate-200 text-slate-600 text-sm font-semibold hover:bg-slate-50 transition">Cancel</a>
            <button type="submit" @disabled($eligibleBookings->isEmpty()) class="inline-flex items-center justify-center px-5 py-2.5 rounded-xl bg-gradient-to-r from-indigo-500 to-fuchsia-500 text-white text-sm font-semibold shadow-lg shadow-indigo-500/20 transition disabled:cursor-not-allowed disabled:opacity-50">Submit Refund Request</button>
        </div>
    </form>
</div>
@endsection
