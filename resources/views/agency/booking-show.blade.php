@extends('layouts.panel')

@section('title', 'Booking ' . $booking->booking_reference)

@section('content')
<div class="max-w-4xl">
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-3">
            <a href="{{ route('agency.bookings.index') }}" class="text-slate-400 hover:text-slate-600 transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            </a>
            <div>
                <h2 class="text-xl font-bold text-slate-900">Booking {{ $booking->booking_reference }}</h2>
                <p class="text-sm text-slate-500 mt-0.5">Created {{ $booking->created_at?->diffForHumans() }}</p>
            </div>
        </div>
        @if (in_array($booking->booking_status, ['booked', 'processing'], true))
            <button
                x-data
                @click="
                    if (confirm('Cancel this booking and initiate a refund?')) {
                        document.getElementById('cancel-form').submit();
                    }
                "
                class="inline-flex items-center gap-2 px-4 py-2 bg-white border border-red-200 text-red-700 text-sm font-medium rounded-lg hover:bg-red-50 transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                Cancel Booking
            </button>
        @endif
    </div>

    @if (session('success'))
        <div class="mb-6 px-4 py-3 bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-xl text-sm">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="mb-6 px-4 py-3 bg-red-50 border border-red-200 text-red-700 rounded-xl text-sm">{{ session('error') }}</div>
    @endif

    @if ($svpError)
        <div class="mb-6 px-4 py-3 bg-amber-50 border border-amber-200 text-amber-700 rounded-xl text-sm">{{ $svpError }}</div>
    @endif

    {{-- Summary --}}
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden mb-6">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-slate-700">Booking Summary</h3>
            @php
                $statusColors = [
                    'pending'     => 'bg-amber-50 text-amber-700 border-amber-200',
                    'processing'  => 'bg-blue-50 text-blue-700 border-blue-200',
                    'booked'      => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                    'failed'      => 'bg-red-50 text-red-700 border-red-200',
                    'cancelled'   => 'bg-slate-100 text-slate-600 border-slate-200',
                    'refunded'    => 'bg-purple-50 text-purple-700 border-purple-200',
                ];
                $color = $statusColors[$booking->booking_status] ?? 'bg-slate-50 text-slate-700 border-slate-200';
            @endphp
            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium border {{ $color }}">
                {{ $booking->booking_status }}
            </span>
        </div>
        <dl class="divide-y divide-slate-100">
            <div class="px-6 py-3 grid grid-cols-3 gap-4">
                <dt class="text-xs font-medium text-slate-400 uppercase tracking-wide">Reference</dt>
                <dd class="col-span-2 text-sm text-slate-700 font-mono">{{ $booking->booking_reference }}</dd>
            </div>
            <div class="px-6 py-3 grid grid-cols-3 gap-4">
                <dt class="text-xs font-medium text-slate-400 uppercase tracking-wide">Candidate</dt>
                <dd class="col-span-2 text-sm text-slate-700">{{ $booking->credential?->full_name ?? '—' }}</dd>
            </div>
            <div class="px-6 py-3 grid grid-cols-3 gap-4">
                <dt class="text-xs font-medium text-slate-400 uppercase tracking-wide">Exam Session</dt>
                <dd class="col-span-2 text-sm text-slate-700">
                    <span class="font-mono">{{ $booking->exam_session_id ?? '—' }}</span>
                    @if ($booking->exam_session_name)<span class="block text-xs text-slate-500 mt-1">{{ $booking->exam_session_name }}</span>@endif
                </dd>
            </div>
            <div class="px-6 py-3 grid grid-cols-3 gap-4">
                <dt class="text-xs font-medium text-slate-400 uppercase tracking-wide">Category</dt>
                <dd class="col-span-2 text-sm text-slate-700">{{ $booking->category_id ?? '—' }}</dd>
            </div>
            <div class="px-6 py-3 grid grid-cols-3 gap-4">
                <dt class="text-xs font-medium text-slate-400 uppercase tracking-wide">Test Center</dt>
                <dd class="col-span-2 text-sm text-slate-700">
                    {{ $booking->test_center_name ?? '—' }}
                    @if ($booking->test_center_id)<span class="block text-xs text-slate-500 mt-1">SVP ID: {{ $booking->test_center_id }}</span>@endif
                </dd>
            </div>
            <div class="px-6 py-3 grid grid-cols-3 gap-4">
                <dt class="text-xs font-medium text-slate-400 uppercase tracking-wide">City / Exam Date</dt>
                <dd class="col-span-2 text-sm text-slate-700">{{ $booking->city ?? '—' }} @if ($booking->exam_date) · {{ $booking->exam_date->format('Y-m-d') }} @endif</dd>
            </div>
            <div class="px-6 py-3 grid grid-cols-3 gap-4">
                <dt class="text-xs font-medium text-slate-400 uppercase tracking-wide">Occupation</dt>
                <dd class="col-span-2 text-sm text-slate-700">{{ $booking->occupation_id ?? '—' }}</dd>
            </div>
            <div class="px-6 py-3 grid grid-cols-3 gap-4">
                <dt class="text-xs font-medium text-slate-400 uppercase tracking-wide">Agency ID</dt>
                <dd class="col-span-2 text-sm text-slate-700">{{ $booking->agency_id }}</dd>
            </div>
            <div class="px-6 py-3 grid grid-cols-3 gap-4">
                <dt class="text-xs font-medium text-slate-400 uppercase tracking-wide">Notes</dt>
                <dd class="col-span-2 text-sm text-slate-700">{{ $booking->notes ?: '—' }}</dd>
            </div>
        </dl>
    </div>

    {{-- SVP validation result --}}
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden mb-6">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-slate-700">SVP Validation</h3>
            @if ($svpValidation)
                @php $v = is_array($svpValidation) ? $svpValidation : ($svpValidation->data ?? $svpValidation); @endphp
                <span class="text-xs text-slate-400">{{ is_array($v) ? count($v) . ' fields' : 'raw response' }}</span>
            @endif
        </div>
        <div class="px-6 py-4">
            @if ($svpValidation)
                <pre class="text-xs bg-slate-50 p-4 rounded-lg overflow-auto max-h-80">{{ json_encode(is_array($svpValidation) ? $svpValidation : ($svpValidation->data ?? $svpValidation), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
            @else
                <p class="text-sm text-slate-400">No validation data available.</p>
            @endif
        </div>
    </div>

    {{-- Local booking log --}}
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100">
            <h3 class="text-sm font-semibold text-slate-700">Booking Log</h3>
        </div>
        <div class="divide-y divide-slate-100">
            @forelse ($booking->logs as $log)
                <div class="px-6 py-3 flex items-start justify-between">
                    <div>
                        <p class="text-sm font-medium text-slate-700">{{ $log->event_type }}</p>
                        @if ($log->payload)
                            <pre class="text-[10px] text-slate-500 mt-1">{{ json_encode($log->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                        @endif
                    </div>
                    <span class="text-xs text-slate-400">{{ $log->created_at?->diffForHumans() }}</span>
                </div>
            @empty
                <p class="px-6 py-6 text-center text-sm text-slate-400">No log entries yet.</p>
            @endforelse
        </div>
    </div>

    {{-- Hidden cancel form --}}
    @if (in_array($booking->booking_status, ['booked', 'processing'], true))
        <form id="cancel-form" method="POST" action="{{ route('agency.bookings.cancel', $booking) }}" class="hidden">
            @csrf
            <input type="hidden" name="amount" value="0">
            <input type="hidden" name="reason" value="Cancelled by user from booking detail page">
        </form>
    @endif
</div>
@endsection
</content>
<task_progress>
- [x] Built Agency/BookingController + routes + sidebar + listing + wizard views
- [x] Write agency/booking-show.blade.php detail
- [ ] Verify pages render (200)
- [ ] Report
