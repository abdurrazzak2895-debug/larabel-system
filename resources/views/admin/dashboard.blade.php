@extends('layouts.panel')

@section('title', 'Admin Dashboard')
@section('page-title', 'Super Admin Dashboard')

@section('content')
{{-- Hero --}}
<div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-slate-900 via-indigo-900 to-fuchsia-900 p-6 sm:p-8 text-white shadow-xl shadow-indigo-500/10 mb-6">
    <div class="absolute -top-24 -right-24 w-72 h-72 bg-fuchsia-500/20 rounded-full blur-3xl"></div>
    <div class="absolute -bottom-24 -left-16 w-72 h-72 bg-indigo-500/20 rounded-full blur-3xl"></div>
    <div class="relative flex flex-col lg:flex-row lg:items-end lg:justify-between gap-6">
        <div>
            <p class="text-xs font-semibold uppercase tracking-widest text-indigo-300 mb-2">Platform Wallet Balance</p>
            <p class="text-4xl sm:text-5xl font-black tracking-tight">
                {{ number_format($totalWalletBalance, 2) }}
                <span class="text-lg sm:text-xl font-bold text-indigo-300">BDT</span>
            </p>
            <div class="flex flex-wrap gap-4 mt-4 text-sm">
                <span class="inline-flex items-center gap-1.5 text-slate-300">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span>
                    {{ $activeUsers }} active users
                </span>
                <span class="inline-flex items-center gap-1.5 text-slate-300">
                    <span class="w-1.5 h-1.5 rounded-full bg-amber-400"></span>
                    {{ $totalAgencies }} agencies
                </span>
                <span class="inline-flex items-center gap-1.5 text-slate-300">
                    <span class="w-1.5 h-1.5 rounded-full bg-fuchsia-400"></span>
                    API {{ strtoupper($apiHealth['status'] ?? 'unknown') }}
                </span>
            </div>
        </div>
        <div class="flex items-center gap-3">
            <a href="{{ route('admin.agencies.create') }}"
               class="inline-flex items-center gap-2 px-5 py-2.5 bg-gradient-to-r from-indigo-500 to-fuchsia-500 hover:from-indigo-400 hover:to-fuchsia-400 text-white text-sm font-semibold rounded-xl shadow-lg shadow-indigo-900/40 transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                New Agency
            </a>
        </div>
    </div>
</div>

{{-- Stat cards --}}
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
    @php
        $stats = [
            ['label' => 'Daily Bookings', 'value' => $dailyBookings, 'icon' => 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z', 'color' => 'from-indigo-500 to-blue-500'],
            ['label' => 'Failed Today', 'value' => $failedBookings, 'icon' => 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z', 'color' => 'from-rose-500 to-red-500'],
            ['label' => "Today's Deposits", 'value' => $todaysDeposits, 'icon' => 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M21 12a9 9 0 11-18 0 9 9 0 0118 0z', 'color' => 'from-emerald-500 to-teal-500'],
            ['label' => "Today's Refunds", 'value' => $todaysRefunds, 'icon' => 'M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15', 'color' => 'from-fuchsia-500 to-purple-500'],
        ];
    @endphp
    @foreach ($stats as $s)
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 flex items-center gap-4">
            <div class="w-12 h-12 rounded-xl bg-gradient-to-br {{ $s['color'] }} flex items-center justify-center text-white shrink-0">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $s['icon'] }}"/></svg>
            </div>
            <div>
                <p class="text-2xl font-bold text-slate-900 leading-none">{{ $s['value'] }}</p>
                <p class="text-xs text-slate-500 mt-1">{{ $s['label'] }}</p>
            </div>
        </div>
    @endforeach
</div>

{{-- Booking summary --}}
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 mb-8">
    @php
        $bookingStats = [
            ['label' => 'Total Bookings', 'value' => number_format($bookingSummary['total']), 'color' => 'text-indigo-700 bg-indigo-50'],
            ['label' => 'Total Booking Amount', 'value' => number_format($bookingSummary['amount'], 2) . ' BDT', 'color' => 'text-emerald-700 bg-emerald-50'],
            ['label' => 'Booked', 'value' => number_format($bookingSummary['booked']), 'color' => 'text-blue-700 bg-blue-50'],
            ['label' => 'Pending / Processing', 'value' => number_format($bookingSummary['pending']), 'color' => 'text-amber-700 bg-amber-50'],
            ['label' => 'Failed', 'value' => number_format($bookingSummary['failed']), 'color' => 'text-rose-700 bg-rose-50'],
        ];
    @endphp
    @foreach ($bookingStats as $stat)
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $stat['label'] }}</p>
            <p class="text-xl font-bold mt-2 {{ $stat['color'] }} inline-block px-2.5 py-1 rounded-lg">{{ $stat['value'] }}</p>
        </div>
    @endforeach
</div>

<div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden mb-6">
    <div class="px-6 py-4 border-b border-slate-100">
        <h3 class="text-sm font-semibold text-slate-700">Agency Booking Summary</h3>
        <p class="text-xs text-slate-400 mt-0.5">Booking totals and status counts for every agency and its users</p>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-left">
                <tr>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Agency</th>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Users</th>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Bookings</th>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Booked</th>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Failed</th>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Total Amount</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($agencyBookingSummary as $agency)
                    <tr class="hover:bg-slate-50/60 transition">
                        <td class="px-6 py-4"><span class="font-medium text-slate-700">{{ $agency->name }}</span><span class="block text-xs text-slate-400">{{ $agency->code }}</span></td>
                        <td class="px-6 py-4 text-slate-600">{{ number_format($agency->user_count) }}</td>
                        <td class="px-6 py-4 font-semibold text-slate-700">{{ number_format($agency->booking_count) }}</td>
                        <td class="px-6 py-4 text-emerald-700">{{ number_format($agency->booked_count) }}</td>
                        <td class="px-6 py-4 text-rose-700">{{ number_format($agency->failed_count) }}</td>
                        <td class="px-6 py-4 font-semibold text-slate-700">{{ number_format((float) ($agency->bookings_sum_portal_booking_fee ?? 0), 2) }} BDT</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-6 py-10 text-center text-sm text-slate-400">No agency booking data yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- Revenue overview --}}
<div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
        <div>
            <h3 class="text-sm font-semibold text-slate-700">Revenue Overview</h3>
            <p class="text-xs text-slate-400 mt-0.5">Daily platform revenue trend</p>
        </div>
        <span class="text-xs font-medium bg-slate-100 text-slate-600 px-3 py-1.5 rounded-full">Queue: {{ $queueStatus }}</span>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-left">
                <tr>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Date</th>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Total</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($revenueOverview as $row)
                    <tr class="hover:bg-slate-50/50 transition">
                        <td class="px-6 py-4 text-slate-600">{{ $row['date'] }}</td>
                        <td class="px-6 py-4 font-bold text-slate-900">{{ number_format($row['total'], 2) }} <span class="text-xs font-medium text-slate-400">BDT</span></td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="2" class="px-6 py-10 text-center text-sm text-slate-400">No revenue data yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- Live booking logs --}}
<div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden mt-6">
    <div class="px-6 py-4 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
        <div>
            <h3 class="text-sm font-semibold text-slate-700">Live Booking Activity</h3>
            <p class="text-xs text-slate-400 mt-0.5">Latest booking events across every agency and its users</p>
        </div>
        <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-emerald-700 bg-emerald-50 border border-emerald-200 px-3 py-1.5 rounded-full">
            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
            Live logs
        </span>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-left">
                <tr>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Agency</th>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">User</th>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Booking</th>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Event</th>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Status</th>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Time</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($liveBookingLogs as $log)
                    @php
                        $booking = $log->booking;
                        $eventLabel = ucwords(str_replace('_', ' ', $log->event_type));
                        $status = $booking?->booking_status ?? 'unknown';
                        $statusStyle = match ($status) {
                            'booked' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                            'failed', 'cancelled', 'refunded' => 'bg-red-50 text-red-700 border-red-200',
                            'processing' => 'bg-blue-50 text-blue-700 border-blue-200',
                            default => 'bg-amber-50 text-amber-700 border-amber-200',
                        };
                    @endphp
                    <tr class="hover:bg-slate-50/60 transition">
                        <td class="px-6 py-4 text-slate-700">
                            <div class="font-medium">{{ $booking?->agency?->name ?? 'Unknown agency' }}</div>
                            @if ($booking?->agency?->code)
                                <div class="text-xs text-slate-400">{{ $booking->agency->code }}</div>
                            @endif
                        </td>
                        <td class="px-6 py-4 text-slate-700">
                            <div class="font-medium">{{ $booking?->user?->name ?? 'Agency account' }}</div>
                            @if ($booking?->user?->email)
                                <div class="text-xs text-slate-400">{{ $booking->user->email }}</div>
                            @endif
                        </td>
                        <td class="px-6 py-4">
                            <div class="font-mono text-xs font-semibold text-slate-700">#{{ $booking?->id ?? $log->booking_id }}</div>
                            <div class="text-xs text-slate-400">{{ $booking?->booking_reference ?? 'No reference' }}</div>
                        </td>
                        <td class="px-6 py-4 text-slate-700">{{ $eventLabel }}</td>
                        <td class="px-6 py-4">
                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium border {{ $statusStyle }}">{{ ucfirst($status) }}</span>
                        </td>
                        <td class="px-6 py-4 text-xs text-slate-500 whitespace-nowrap">{{ $log->created_at?->format('M d, Y g:i A') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-12 text-center text-sm text-slate-400">No booking activity has been recorded yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
