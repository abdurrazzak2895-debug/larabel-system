@extends('layouts.user')

@section('title', 'Booking #' . $booking->id)
@section('page-title', 'Booking Details')

@section('content')
@php
    $statusStyles = [
        'pending'    => ['bg-amber-50 text-amber-700 border-amber-200', 'Pending'],
        'processing' => ['bg-blue-50 text-blue-700 border-blue-200', 'Processing'],
        'booked'     => ['bg-emerald-50 text-emerald-700 border-emerald-200', 'Booked'],
        'failed'     => ['bg-red-50 text-red-700 border-red-200', 'Failed'],
        'cancelled'  => ['bg-slate-100 text-slate-600 border-slate-200', 'Cancelled'],
        'refunded'   => ['bg-purple-50 text-purple-700 border-purple-200', 'Refunded'],
    ];
    [$statusStyle, $statusLabel] = $statusStyles[$booking->booking_status] ?? [['bg-slate-50 text-slate-700 border-slate-200'], ucfirst($booking->booking_status)];
    $latestAttempt = $attempts?->sortByDesc('id')->first();
    $requestPayload = is_array($latestAttempt?->request_payload) ? $latestAttempt->request_payload : [];
    $displayCenterId = $booking->test_center_id
        ?: data_get($requestPayload, 'test_center_id')
        ?: data_get($requestPayload, 'site_id');
    $displayCenterName = $booking->test_center_name
        ?: data_get($requestPayload, 'test_center_name')
        ?: data_get($requestPayload, 'site_name')
        ?: data_get($requestPayload, 'test_center.name');
    if (! $displayCenterName && $displayCenterId) {
        foreach ((array) config('svp.dhaka_test_centers', []) as $center) {
            if ((string) data_get($center, 'id') === (string) $displayCenterId) {
                $displayCenterName = data_get($center, 'name') ?: data_get($center, 'english_name');
                break;
            }
        }
    }
    $statusSteps = ['pending', 'processing', 'booked'];
    $currentStep = array_search($booking->booking_status, $statusSteps);
@endphp
<div class="space-y-6">

    @if (session('success'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ session('error') }}</div>
    @endif

    {{-- ===================== Header ===================== --}}
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <a href="{{ route('user.bookings.index') }}" class="w-9 h-9 rounded-xl border border-slate-200 bg-white text-slate-500 flex items-center justify-center hover:text-slate-900 hover:border-slate-300 transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            </a>
            <div>
                <div class="flex items-center gap-3">
                    <h1 class="text-2xl font-bold text-slate-900">Booking <span class="font-mono text-slate-500">{{ $booking->booking_reference ?? ('#' . $booking->id) }}</span></h1>
                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium border {{ $statusStyle }}">{{ $statusLabel }}</span>
                </div>
                <p class="text-sm text-slate-500 mt-1">Created {{ $booking->created_at->format('M d, Y g:i A') }}</p>
            </div>
        </div>
        <div class="flex flex-wrap items-center justify-end gap-2">
            @if ($booking->reservation_id && ctype_digit((string) $booking->reservation_id))
                <form method="POST" action="{{ route('user.bookings.svp-cancel', ['reservation' => $booking->reservation_id]) }}" onsubmit="return confirm('Cancel this live SVP reservation? This action cannot be undone.');">
                    @csrf
                    <button type="submit" class="inline-flex items-center justify-center gap-2 px-4 py-2.5 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold rounded-xl transition">
                        Cancel SVP Reservation
                    </button>
                </form>
            @endif
            <a href="{{ route('user.bookings.create') }}" class="inline-flex items-center justify-center gap-2 px-4 py-2.5 bg-gradient-to-r from-indigo-500 to-fuchsia-500 hover:from-indigo-600 hover:to-fuchsia-600 text-white text-sm font-semibold rounded-xl shadow-lg shadow-indigo-500/25 transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                New Booking
            </a>
        </div>
    </div>

    {{-- ===================== Progress ===================== --}}
    @if ($currentStep !== false)
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6">
        <h3 class="text-sm font-semibold text-slate-800 mb-5">Booking Progress</h3>
        <div class="flex items-center">
            @foreach ($statusSteps as $i => $step)
            <div class="flex items-center {{ $i < count($statusSteps) - 1 ? 'flex-1' : '' }}">
                <div class="flex flex-col items-center">
                    <div class="w-10 h-10 rounded-full flex items-center justify-center text-sm font-bold border-2 transition
                        {{ $i < $currentStep ? 'bg-indigo-600 border-indigo-600 text-white' : ($i === $currentStep ? 'bg-indigo-50 border-indigo-600 text-indigo-600' : 'bg-white border-slate-200 text-slate-400') }}">
                        @if ($i < $currentStep)
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        @else
                        {{ $i + 1 }}
                        @endif
                    </div>
                    <span class="text-[11px] font-medium mt-2 {{ $i <= $currentStep ? 'text-slate-700' : 'text-slate-400' }}">{{ ucfirst($step) }}</span>
                </div>
                @if ($i < count($statusSteps) - 1)
                <div class="flex-1 h-1 mx-3 -mt-5 rounded-full {{ $i < $currentStep ? 'bg-indigo-500' : 'bg-slate-100' }}"></div>
                @endif
            </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- ===================== Booking info grid ===================== --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
            <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">Booking Reference</p>
            <p class="text-sm font-mono font-semibold text-slate-800 mt-1 break-all">{{ $booking->booking_reference ?? ('#' . $booking->id) }}</p>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
            <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">Exam Session</p>
            <p class="text-sm font-semibold text-slate-800 mt-1 font-mono">{{ $booking->exam_session_id ?? '—' }}</p>
            @if ($booking->exam_session_name)
            <p class="text-xs text-slate-500 mt-1">{{ $booking->exam_session_name }}</p>
            @endif
        </div>
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
            <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">Test Center</p>
            <p class="text-sm font-semibold text-slate-800 mt-1">{{ $displayCenterName ?: 'Center data unavailable' }}</p>
            @if (! $displayCenterName)
            <p class="text-xs text-amber-600 mt-1">SVP center was not saved with this legacy booking.</p>
            @endif
            @if ($booking->city)
            <p class="text-xs text-slate-400 mt-1">{{ $booking->city }}</p>
            @endif
        </div>
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
            <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">Candidate</p>
            <p class="text-sm font-semibold text-slate-800 mt-1">{{ $booking->credential->full_name ?? '—' }}</p>
            @if ($booking->occupation_id)
            <p class="text-xs text-slate-400 mt-0.5">Occupation: {{ $booking->occupation_id }}</p>
            @endif
            @if ($booking->category_id)
            <p class="text-xs text-slate-400 mt-0.5">Category: {{ $booking->category_id }}</p>
            @endif
            @if ($booking->exam_date)
            <p class="text-xs text-slate-400 mt-0.5">Exam date: {{ $booking->exam_date->format('Y-m-d') }}</p>
            @endif
        </div>
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
            <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">Booking ID</p>
            <p class="text-sm font-mono font-semibold text-slate-800 mt-1">#{{ $booking->id }}</p>
        </div>
    </div>

    @if ($booking->notes)
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
        <p class="text-xs font-medium text-slate-500 uppercase tracking-wide mb-1.5">Notes</p>
        <p class="text-sm text-slate-600">{{ $booking->notes }}</p>
    </div>
    @endif

    {{-- ===================== Attempts ===================== --}}
    @if ($attempts->isNotEmpty())
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100">
            <h3 class="text-sm font-semibold text-slate-800">Booking Attempts</h3>
            <p class="text-xs text-slate-400 mt-0.5">API submission attempts for this booking</p>
        </div>
        <div class="divide-y divide-slate-100">
            @foreach ($attempts as $attempt)
            <div class="px-6 py-4 flex items-center justify-between gap-4">
                <div class="min-w-0">
                    <p class="text-xs font-medium text-slate-700">
                        Attempt #{{ $attempt->attempt_number ?? $loop->iteration }}
                        <span class="inline-flex items-center ml-2 px-2 py-0.5 rounded-full text-[10px] font-semibold border
                            {{ $attempt->status === 'success' ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : ($attempt->status === 'failed' ? 'bg-red-50 text-red-700 border-red-200' : 'bg-amber-50 text-amber-700 border-amber-200') }}">
                            {{ ucfirst($attempt->status ?? 'pending') }}
                        </span>
                    </p>
                    @if ($attempt->error_message)
                    <p class="text-[11px] text-red-600 mt-1 break-words">{{ $attempt->error_message }}</p>
                    @endif
                    <p class="text-[11px] text-slate-400 mt-1">{{ $attempt->created_at->format('M d, Y g:i A') }}</p>
                </div>
                @if ($attempt->provider_response)
                <details class="shrink-0">
                    <summary class="text-xs font-medium text-brand-600 hover:text-brand-700 cursor-pointer">Response</summary>
                    <pre class="mt-2 p-3 bg-slate-50 rounded-lg text-[10px] text-slate-600 overflow-x-auto max-w-sm">{{ json_encode($attempt->provider_response, JSON_PRETTY_PRINT) }}</pre>
                </details>
                @endif
            </div>
            @endforeach
        </div>
    </div>
    @endif


    {{-- ===================== Refunds + Activity grid ===================== --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        {{-- Refunds --}}
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6">
            <h3 class="text-sm font-semibold text-slate-800">Refund Requests</h3>
            <p class="text-xs text-slate-400 mt-0.5">Money back for this booking</p>
            <div class="mt-4">
                @forelse ($refunds as $refund)
                @php
                    $refundStyles = [
                        'pending'   => 'bg-amber-50 text-amber-700 border-amber-200',
                        'approved'  => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                        'rejected'  => 'bg-red-50 text-red-700 border-red-200',
                        'processed' => 'bg-sky-50 text-sky-700 border-sky-200',
                    ];
                    $refundStyle = $refundStyles[$refund->status] ?? 'bg-slate-50 text-slate-700 border-slate-200';
                @endphp
                <div class="rounded-xl border border-slate-100 bg-slate-50/50 p-4 mb-3">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-medium text-slate-500">#{{ $refund->id }} · {{ $refund->created_at->format('M d, Y') }}</span>
                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium border {{ $refundStyle }}">{{ ucfirst($refund->status) }}</span>
                    </div>
                    <p class="text-lg font-bold text-slate-900 mt-2">{{ number_format($refund->amount ?? 0, 2) }} <span class="text-sm font-medium text-slate-400">BDT</span></p>
                    <p class="text-xs text-slate-500 mt-1">{{ $refund->reason }}</p>
                </div>
                @empty
                <p class="text-sm text-slate-400">No refund requests for this booking.</p>
                @endforelse
                <a href="{{ route('user.refunds.create', ['booking' => $booking->id]) }}" class="inline-flex items-center gap-2 mt-2 text-xs font-semibold text-brand-600 hover:text-brand-700 transition">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    Request a refund
                </a>
            </div>
        </div>

        {{-- Activity --}}
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100">
                <h3 class="text-sm font-semibold text-slate-800">Activity Timeline</h3>
                <p class="text-xs text-slate-400 mt-0.5">Status changes and events for this booking</p>
            </div>
            <div class="p-6">
                @forelse ($logs as $log)
                <div class="relative pl-6 pb-6 last:pb-0 border-l-2 border-slate-100">
                    <span class="absolute -left-[9px] top-0.5 w-4 h-4 rounded-full border-2 border-white bg-indigo-500 shadow"></span>
                    <p class="text-sm font-medium text-slate-800">
                        {{ $log->new_status ? 'Status changed to ' . ucfirst($log->new_status) : 'Booking event' }}
                        @if ($log->old_status)
                        <span class="text-slate-400">(from {{ ucfirst($log->old_status) }})</span>
                        @endif
                    </p>
                    @if ($log->notes)
                    <p class="text-xs text-slate-500 mt-1">{{ $log->notes }}</p>
                    @endif
                    <p class="text-xs text-slate-400 mt-1.5">{{ $log->created_at->format('M d, Y g:i A') }} · {{ $log->changed_by ?? 'System' }}</p>
                </div>
                @empty
                <div class="text-center py-8">
                    <div class="w-12 h-12 mx-auto rounded-full bg-slate-100 text-slate-400 flex items-center justify-center mb-3">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <p class="text-sm text-slate-400">No activity recorded yet.</p>
                </div>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection

