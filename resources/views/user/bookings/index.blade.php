@extends('layouts.user')

@section('title', 'My Bookings')
@section('page-title', 'My Bookings')

@section('content')
<div class="space-y-6">

    {{-- ===================== Page header ===================== --}}
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">My Bookings</h1>
            <p class="text-sm text-slate-500 mt-1">Track and manage all your exam bookings.</p>
        </div>
        <a href="{{ route('user.bookings.create') }}" class="inline-flex items-center justify-center gap-2 px-4 py-2.5 bg-gradient-to-r from-indigo-500 to-fuchsia-500 hover:from-indigo-600 hover:to-fuchsia-600 text-white text-sm font-semibold rounded-xl shadow-lg shadow-indigo-500/25 transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            New Booking
        </a>
    </div>

    {{-- ===================== Filter tabs ===================== --}}
    <div class="flex flex-wrap items-center gap-2">
        @php
            $tabs = [
                'all'        => ['All', $counts['all'], 'text-slate-600'],
                'pending'    => ['Pending', $counts['pending'], 'text-amber-600'],
                'processing' => ['Processing', $counts['processing'], 'text-blue-600'],
                'booked'     => ['Booked', $counts['booked'], 'text-emerald-600'],
                'failed'     => ['Failed', $counts['failed'], 'text-red-600'],
                'cancelled'  => ['Cancelled', $counts['cancelled'], 'text-slate-500'],
                'refunded'   => ['Refunded', $counts['refunded'], 'text-purple-600'],
            ];
        @endphp
        @foreach ($tabs as $key => [$label, $count, $color])
        <a href="{{ route('user.bookings.index', array_filter(['status' => $key === 'all' ? null : $key, 'q' => $search ?: null])) }}"
           class="inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-sm font-medium border transition
                  {{ $filter === $key ? 'bg-slate-900 text-white border-slate-900 shadow-sm' : 'bg-white text-slate-600 border-slate-200 hover:border-slate-300 hover:text-slate-900' }}">
            {{ $label }}
            <span class="text-xs font-semibold {{ $filter === $key ? 'text-slate-300' : $color }}">{{ $count }}</span>
        </a>
        @endforeach
    </div>

    {{-- ===================== Bookings table ===================== --}}
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100">
            <form method="GET" action="{{ route('user.bookings.index') }}" class="flex flex-col sm:flex-row sm:items-center gap-3">
                <div class="relative flex-1 max-w-md">
                    <svg class="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    <input type="text" name="q" value="{{ $search }}" placeholder="Search by reference or session ID…"
                           class="w-full pl-10 pr-4 py-2.5 rounded-xl border border-slate-200 text-sm text-slate-700 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/30 focus:border-indigo-400 transition">
                </div>
                <button type="submit" class="px-4 py-2.5 rounded-xl bg-slate-900 text-white text-sm font-medium hover:bg-slate-800 transition">Search</button>
                @if ($search)
                <a href="{{ route('user.bookings.index') }}" class="px-4 py-2.5 rounded-xl border border-slate-200 bg-white text-slate-600 text-sm font-medium hover:bg-slate-50 transition">Clear</a>
                @endif
            </form>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50/70">
                    <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500">
                        <th class="px-6 py-3 font-medium">Reference</th>
                        <th class="px-6 py-3 font-medium">Session</th>
                        <th class="px-6 py-3 font-medium">Candidate</th>
                        <th class="px-6 py-3 font-medium">Status</th>
                        <th class="px-6 py-3 font-medium">Created</th>
                        <th class="px-6 py-3 text-right font-medium">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($bookings as $booking)
                    @php
                        $statusStyles = [
                            'pending'    => ['bg-amber-50 text-amber-700 border-amber-200'],
                            'processing' => ['bg-blue-50 text-blue-700 border-blue-200'],
                            'booked'     => ['bg-emerald-50 text-emerald-700 border-emerald-200'],
                            'failed'     => ['bg-red-50 text-red-700 border-red-200'],
                            'cancelled'  => ['bg-slate-100 text-slate-600 border-slate-200'],
                            'refunded'   => ['bg-purple-50 text-purple-700 border-purple-200'],
                        ];
                        [$statusStyle] = $statusStyles[$booking->booking_status] ?? [['bg-slate-50 text-slate-700 border-slate-200']];
                    @endphp
                    <tr class="hover:bg-slate-50/60 transition">
                        <td class="px-6 py-4 font-mono text-xs text-slate-600">{{ $booking->booking_reference ?? ('#' . $booking->id) }}</td>
                        <td class="px-6 py-4 text-xs text-slate-500">{{ $booking->exam_session_id ?? '—' }}</td>
                        <td class="px-6 py-4">
                            <div class="flex items-center gap-2.5">
                                <span class="w-8 h-8 shrink-0 rounded-full bg-indigo-50 text-indigo-600 flex items-center justify-center text-xs font-bold">{{ strtoupper(substr($booking->credential->full_name ?? 'N/A', 0, 1)) }}</span>
                                <span class="text-xs font-medium text-slate-700">{{ $booking->credential->full_name ?? '—' }}</span>
                            </div>
                        </td>
                        <td class="px-6 py-4">
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium border {{ $statusStyle }}">{{ ucfirst($booking->booking_status) }}</span>
                        </td>
                        <td class="px-6 py-4 text-xs text-slate-500">{{ $booking->created_at->diffForHumans() }}</td>
                        <td class="px-6 py-4 text-right">
                            <a href="{{ route('user.bookings.show', $booking) }}" class="inline-flex items-center gap-1.5 text-xs font-medium text-brand-600 hover:text-brand-700 transition">
                                View Details
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                            </a>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="6" class="px-6 py-16 text-center">
                            <div class="w-14 h-14 mx-auto rounded-full bg-slate-100 text-slate-400 flex items-center justify-center mb-4">
                                <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                            </div>
                            <p class="text-sm font-medium text-slate-600">No bookings found</p>
                            <p class="text-xs text-slate-400 mt-1">{{ $filter !== 'all' || $search ? 'Try adjusting your filters or search terms.' : 'Create your first booking to get started.' }}</p>
                            <a href="{{ route('user.bookings.create') }}" class="inline-block mt-4 px-4 py-2.5 rounded-xl bg-gradient-to-r from-indigo-500 to-fuchsia-500 text-white text-sm font-semibold shadow-lg shadow-indigo-500/25 hover:from-indigo-600 hover:to-fuchsia-600 transition">New Booking</a>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($bookings->hasPages())
        <div class="px-6 py-4 border-t border-slate-100">
            {{ $bookings->links() }}
        </div>
        @endif
    </div>

    {{-- ===================== SVP live reservations ===================== --}}
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
            <div>
                <h2 class="text-base font-semibold text-slate-800">SVP My Bookings</h2>
                <p class="text-xs text-slate-400 mt-1">Live reservations and official tickets from your authenticated SVP account.</p>
            </div>
            @if ($svpUserId)
                <span class="inline-flex items-center gap-1.5 text-xs text-slate-500">
                    <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                    SVP user ID: <span class="font-mono text-slate-700">{{ $svpUserId }}</span>
                </span>
            @endif
        </div>

        @if ($svpError)
            <div class="m-5 flex flex-col sm:flex-row sm:items-center justify-between gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                <span>{{ $svpError }}</span>
                @if (! $hasSvpToken)
                    <a href="{{ route('svp.login.form') }}" class="inline-flex items-center justify-center px-3 py-2 rounded-lg bg-amber-700 text-white text-xs font-semibold hover:bg-amber-800 transition">Sign in with SVP</a>
                @endif
            </div>
        @else
            @php
                $svpPayload = is_array($svpReservations) ? $svpReservations : [];
                $svpItems = array_is_list($svpPayload)
                    ? $svpPayload
                    : (data_get($svpPayload, 'data.exam_reservations')
                        ?? data_get($svpPayload, 'exam_reservations')
                        ?? data_get($svpPayload, 'data.reservations')
                        ?? data_get($svpPayload, 'reservations')
                        ?? data_get($svpPayload, 'data')
                        ?? []);
                if (is_array($svpItems) && isset($svpItems['items'])) {
                    $svpItems = $svpItems['items'];
                }
                if (is_array($svpItems) && ! array_is_list($svpItems) && isset($svpItems['id'])) {
                    $svpItems = [$svpItems];
                }
                if (! is_array($svpItems)) {
                    $svpItems = [];
                }
            @endphp

            <div class="px-6 py-3 border-b border-slate-100 flex items-center justify-between">
                <p class="text-xs text-slate-500">{{ count($svpItems) }} live reservation{{ count($svpItems) === 1 ? '' : 's' }} found</p>
                <span class="text-[11px] text-slate-400">Updated from SVP when this page opened</span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50/70">
                        <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500">
                            <th class="px-6 py-3 font-medium">Reservation</th>
                            <th class="px-6 py-3 font-medium">Exam</th>
                            <th class="px-6 py-3 font-medium">Date / Center</th>
                            <th class="px-6 py-3 font-medium">Status</th>
                            <th class="px-6 py-3 font-medium">Availability</th>
                            <th class="px-6 py-3 text-right font-medium">Ticket</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($svpItems as $reservation)
                            @php
                                $reservation = (array) $reservation;
                                $reservationId = (string) ($reservation['id'] ?? '');
                                $category = (array) ($reservation['category'] ?? []);
                                $center = (array) ($reservation['test_center'] ?? $reservation['testCenter'] ?? []);
                                $session = (array) ($reservation['exam_session'] ?? $reservation['examSession'] ?? []);
                                $examDate = $reservation['exam_date'] ?? $reservation['date'] ?? ($session['date'] ?? null);
                                $centerName = $reservation['test_center_name'] ?? $reservation['center_name'] ?? ($center['english_name'] ?? $center['name'] ?? null);
                                $examName = $reservation['exam_name'] ?? ($category['english_name'] ?? $category['name'] ?? 'SVP Exam');
                                $cancellable = filter_var($reservation['can_be_canceled'] ?? false, FILTER_VALIDATE_BOOLEAN);
                                $reschedulable = filter_var($reservation['can_be_rescheduled'] ?? false, FILTER_VALIDATE_BOOLEAN);
                                $resultState = $reservation['_result_state'] ?? 'pending';
                                $resultLabel = $reservation['_result_label'] ?? 'Pending';
                                $resultPassed = (bool) ($reservation['_result_passed'] ?? false);
                                $resultStyle = match ($resultState) {
                                    'passed' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                                    'failed' => 'bg-red-50 text-red-700 border-red-200',
                                    default => 'bg-amber-50 text-amber-700 border-amber-200',
                                };
                            @endphp
                            <tr class="hover:bg-slate-50/60 transition align-top">
                                <td class="px-6 py-4">
                                    <div class="font-mono text-xs font-semibold text-slate-700">#{{ $reservationId ?: '—' }}</div>
                                    <div class="text-[11px] text-slate-400 mt-1">{{ $reservation['booking_reference'] ?? 'SVP reservation' }}</div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="text-xs font-medium text-slate-700">{{ $examName }}</div>
                                    <div class="text-[11px] text-slate-400 mt-1">Session: {{ $reservation['exam_session_id'] ?? $reservation['session_id'] ?? ($session['id'] ?? '—') }}</div>
                                </td>
                                <td class="px-6 py-4 text-xs text-slate-600">
                                    <div>{{ $examDate ?: 'Date pending' }}</div>
                                    <div class="text-[11px] text-slate-400 mt-1">{{ $centerName ?: 'Center not returned' }}</div>
                                </td>
                                <td class="px-6 py-4 space-y-1.5">
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium border bg-slate-50 text-slate-700 border-slate-200">{{ $reservation['status'] ?? 'Reserved' }}</span>
                                    <div><span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold border {{ $resultStyle }}">Result: {{ $resultLabel }}</span></div>
                                </td>
                                <td class="px-6 py-4 text-xs space-y-1.5">
                                    <div class="{{ $cancellable ? 'text-emerald-600' : 'text-slate-400' }}">Cancel: {{ $cancellable ? 'Available' : 'Unavailable' }}</div>
                                    <div class="{{ $reschedulable ? 'text-emerald-600' : 'text-slate-400' }}">Reschedule: {{ $reschedulable ? 'Available' : 'Unavailable' }}</div>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    @if ($resultPassed && $reservationId !== '' && ctype_digit($reservationId))
                                        <a href="{{ route('user.bookings.svp-ticket', ['reservation' => $reservationId]) }}" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-emerald-700 text-white text-xs font-semibold hover:bg-emerald-800 transition" download>
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H8a2 2 0 01-2-2V5a2 2 0 012-2h5l4 4v11a2 2 0 01-2 2z"/></svg>
                                            Download Certificate
                                        </a>
                                    @elseif ($resultState === 'failed')
                                        <span class="text-xs text-red-600">Certificate unavailable</span>
                                    @else
                                        <span class="text-xs text-amber-600">Awaiting result</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-12 text-center">
                                    <div class="w-12 h-12 mx-auto rounded-full bg-slate-100 text-slate-400 flex items-center justify-center mb-3">
                                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                    </div>
                                    <p class="text-sm font-medium text-slate-600">No live SVP reservations found</p>
                                    <p class="text-xs text-slate-400 mt-1">Completed reservations will appear here with their official ticket.</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
@endsection

