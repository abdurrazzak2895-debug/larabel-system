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
                <span class="text-lg sm:text-xl font-bold text-indigo-300">SAR</span>
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
                        <td class="px-6 py-4 font-bold text-slate-900">{{ number_format($row['total'], 2) }} <span class="text-xs font-medium text-slate-400">SAR</span></td>
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
@endsection
