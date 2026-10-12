@extends('layouts.user')

@section('title', 'Booking History')
@section('page-title', 'Booking History')

@section('content')
<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Booking History</h1>
            <p class="text-sm text-slate-500 mt-1">Review your agency SVP booking attempts, failures, and refund outcomes.</p>
        </div>
        <a href="{{ route('user.bookings.index') }}" class="inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl border border-slate-200 bg-white text-slate-700 text-sm font-semibold hover:bg-slate-50 transition">
            Back to My Bookings
        </a>
    </div>

    @include('user.bookings.partials.portal-history', ['localBookings' => $localBookings])
</div>
@endsection
