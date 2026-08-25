@extends('layouts.panel')

@section('title', 'Agency Dashboard')
@section('page-title', 'Agency Dashboard')

@section('content')
{{-- Hero --}}
<div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-slate-900 via-indigo-900 to-fuchsia-900 p-6 sm:p-8 text-white shadow-xl shadow-indigo-500/10 mb-6">
    <div class="absolute -top-24 -right-24 w-72 h-72 bg-fuchsia-500/20 rounded-full blur-3xl"></div>
    <div class="absolute -bottom-24 -left-16 w-72 h-72 bg-indigo-500/20 rounded-full blur-3xl"></div>
    <div class="relative flex flex-col lg:flex-row lg:items-end lg:justify-between gap-6">
        <div>
            <p class="text-xs font-semibold uppercase tracking-widest text-indigo-300 mb-2">Managed User Wallets</p>
            <p class="text-4xl sm:text-5xl font-black tracking-tight">
                {{ number_format($managedUserBalance, 2) }}
                <span class="text-lg sm:text-xl font-bold text-indigo-300">BDT</span>
            </p>
            <div class="flex flex-wrap gap-4 mt-4 text-sm">
                <span class="inline-flex items-center gap-1.5 text-slate-300">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span>
                    Total available balance across user wallets
                </span>
            </div>
        </div>
        <div class="flex items-center gap-3">
            <a href="{{ route('agency.bookings.create') }}"
               class="inline-flex items-center gap-2 px-5 py-2.5 bg-gradient-to-r from-indigo-500 to-fuchsia-500 hover:from-indigo-400 hover:to-fuchsia-400 text-white text-sm font-semibold rounded-xl shadow-lg shadow-indigo-900/40 transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                New Booking
            </a>
            @if (auth()->user()?->hasPermission('manage agency users'))
                <a href="{{ route('agency.users.index') }}"
                   class="inline-flex items-center gap-2 px-5 py-2.5 bg-white/10 hover:bg-white/15 backdrop-blur text-white text-sm font-medium rounded-xl border border-white/10 transition">
                    Manage Users
                </a>
            @endif
        </div>
    </div>
</div>

{{-- Stat cards --}}
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
    @php
        $stats = [
            ['label' => "Today's Bookings", 'value' => $todayBookings, 'icon' => 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z', 'color' => 'from-indigo-500 to-blue-500'],
            ['label' => 'Failed Today', 'value' => $failedBookings, 'icon' => 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z', 'color' => 'from-rose-500 to-red-500'],
            ['label' => 'Deposits', 'value' => $deposits->count(), 'icon' => 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z', 'color' => 'from-emerald-500 to-teal-500'],
            ['label' => 'Active Users', 'value' => $activeUsers, 'icon' => 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z', 'color' => 'from-fuchsia-500 to-purple-500'],
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

{{-- Recent activity --}}
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    {{-- Recent deposits --}}
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-slate-700">Recent Deposits</h3>
            <a href="{{ route('agency.deposits.index') }}" class="text-xs font-medium text-brand-600 hover:text-brand-700 transition">View all</a>
        </div>
        <div class="divide-y divide-slate-100">
            @forelse ($deposits as $deposit)
                @php
                    $colors = [
                        'pending'  => 'bg-amber-50 text-amber-700 border-amber-200',
                        'approved' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                        'rejected' => 'bg-red-50 text-red-700 border-red-200',
                    ];
                    $color = $colors[$deposit->status] ?? 'bg-slate-50 text-slate-600 border-slate-200';
                @endphp
                <div class="px-6 py-3.5 flex items-center justify-between gap-4">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-slate-700">#{{ $deposit->id }} · {{ $deposit->payment_method ?? '—' }}</p>
                        <p class="text-xs text-slate-400 mt-0.5">{{ $deposit->created_at?->diffForHumans() }}</p>
                    </div>
                    <div class="flex items-center gap-3 shrink-0">
                        <span class="text-sm font-bold text-slate-900">{{ number_format($deposit->amount, 2) }}</span>
                        <span class="inline-flex px-2 py-0.5 rounded text-xs font-medium border {{ $color }}">{{ ucfirst($deposit->status) }}</span>
                    </div>
                </div>
            @empty
                <p class="px-6 py-10 text-center text-sm text-slate-400">No deposit requests yet.</p>
            @endforelse
        </div>
    </div>

    {{-- Recent refunds --}}
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-slate-700">Recent Refunds</h3>
            <a href="{{ route('agency.refunds.index') }}" class="text-xs font-medium text-brand-600 hover:text-brand-700 transition">View all</a>
        </div>
        <div class="divide-y divide-slate-100">
            @forelse ($refunds as $refund)
                @php
                    $colors = [
                        'pending'  => 'bg-amber-50 text-amber-700 border-amber-200',
                        'approved' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                        'rejected' => 'bg-red-50 text-red-700 border-red-200',
                    ];
                    $color = $colors[$refund->status] ?? 'bg-slate-50 text-slate-600 border-slate-200';
                @endphp
                <div class="px-6 py-3.5 flex items-center justify-between gap-4">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-slate-700">Booking #{{ $refund->booking_id }}</p>
                        <p class="text-xs text-slate-400 mt-0.5 truncate">{{ $refund->reason }}</p>
                    </div>
                    <div class="flex items-center gap-3 shrink-0">
                        <span class="text-sm font-bold text-slate-900">{{ number_format($refund->amount, 2) }}</span>
                        <span class="inline-flex px-2 py-0.5 rounded text-xs font-medium border {{ $color }}">{{ ucfirst($refund->status) }}</span>
                    </div>
                </div>
            @empty
                <p class="px-6 py-10 text-center text-sm text-slate-400">No refund requests yet.</p>
            @endforelse
        </div>
    </div>
</div>
@endsection
