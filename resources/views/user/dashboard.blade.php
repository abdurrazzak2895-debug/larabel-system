@extends('layouts.user')

@section('title', 'Dashboard')
@section('page-title', 'Dashboard')

@section('content')
<div class="space-y-6">

    {{-- ===================== Welcome hero ===================== --}}
    <div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-slate-900 via-indigo-950 to-fuchsia-950 px-6 py-7 sm:px-8 text-white shadow-xl shadow-indigo-900/20">
        <div class="absolute -top-24 -right-24 w-72 h-72 bg-indigo-500/30 rounded-full blur-3xl pointer-events-none"></div>
        <div class="absolute -bottom-24 -left-16 w-64 h-64 bg-fuchsia-500/20 rounded-full blur-3xl pointer-events-none"></div>
        <div class="relative flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <p class="text-indigo-300 text-sm font-medium">Welcome back,</p>
                <h2 class="text-2xl font-bold mt-1">{{ Auth::user()->name }} 👋</h2>
                <p class="text-slate-400 text-sm mt-1.5">{{ now()->format('l, F j, Y') }} · Here's what's happening with your exam bookings.</p>
            </div>
            <div class="flex flex-wrap gap-3">
                <a href="{{ route('user.bookings.create') }}" class="inline-flex items-center gap-2 px-4 py-2.5 bg-white/10 hover:bg-white/20 border border-white/10 text-white text-sm font-medium rounded-xl transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    New Booking
                </a>
                <a href="{{ route('user.deposits.create') }}" class="inline-flex items-center gap-2 px-4 py-2.5 bg-gradient-to-r from-indigo-500 to-fuchsia-500 hover:from-indigo-600 hover:to-fuchsia-600 text-white text-sm font-semibold rounded-xl shadow-lg shadow-indigo-500/25 transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    Add Funds
                </a>
            </div>
        </div>
    </div>

    {{-- ===================== Stat cards ===================== --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 flex items-start gap-4">
            <div class="w-11 h-11 shrink-0 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h2m-5-7h16a1 1 0 011 1v10a1 1 0 01-1 1H5a2 2 0 01-2-2V7a2 2 0 012-2z"/></svg>
            </div>
            <div class="min-w-0">
                <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">Wallet Balance</p>
                <p class="text-xl font-bold text-slate-900 mt-0.5">{{ number_format($walletBalance, 2) }} <span class="text-sm font-medium text-slate-400">SAR</span></p>
                <p class="text-xs text-slate-400 mt-0.5 truncate">Reserved: {{ number_format($reservedBalance, 2) }} · Credit: {{ number_format($creditLimit, 2) }}</p>
            </div>
        </div>

        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 flex items-start gap-4">
            <div class="w-11 h-11 shrink-0 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5h7a2 2 0 012 2v12a2 2 0 01-2 2H7a2 2 0 01-2-2V7a2 2 0 012-2h2m2 4h4m-4 4h4m-4 4h4"/></svg>
            </div>
            <div class="min-w-0">
                <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">Total Bookings</p>
                <p class="text-xl font-bold text-slate-900 mt-0.5">{{ $totalBookings }}</p>
                <p class="text-xs text-slate-400 mt-0.5 truncate">{{ $confirmedBookings }} confirmed · {{ $pendingBookings }} in progress</p>
            </div>
        </div>

        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 flex items-start gap-4">
            <div class="w-11 h-11 shrink-0 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <div class="min-w-0">
                <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">Confirmed</p>
                <p class="text-xl font-bold text-slate-900 mt-0.5">{{ $confirmedBookings }}</p>
                <p class="text-xs text-emerald-600 mt-0.5">Successfully booked</p>
            </div>
        </div>

        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 flex items-start gap-4">
            <div class="w-11 h-11 shrink-0 rounded-xl bg-red-50 text-red-600 flex items-center justify-center">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <div class="min-w-0">
                <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">Failed</p>
                <p class="text-xl font-bold text-slate-900 mt-0.5">{{ $failedBookings }}</p>
                <p class="text-xs text-slate-400 mt-0.5">Needs attention</p>
            </div>
        </div>
    </div>

    {{-- ===================== Upcoming exam banner ===================== --}}
    @if ($upcomingExam)
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 sm:p-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div class="flex items-center gap-4">
            <div class="w-12 h-12 shrink-0 rounded-xl bg-gradient-to-br from-indigo-500 to-fuchsia-500 text-white flex items-center justify-center">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
            </div>
            <div>
                <p class="text-xs font-semibold text-brand-600 uppercase tracking-wide">Upcoming Exam</p>
                <p class="text-sm font-semibold text-slate-800 mt-0.5">
                    {{ $upcomingExam->credential->full_name ?? 'Exam booking' }}
                    <span class="text-slate-400 font-normal">· Session {{ $upcomingExam->exam_session_id ?? '—' }}</span>
                </p>
                <p class="text-xs text-slate-400 mt-1">Reference: <span class="font-mono">{{ $upcomingExam->booking_reference ?? ('#' . $upcomingExam->id) }}</span></p>
            </div>
        </div>
        <a href="{{ route('user.bookings.show', $upcomingExam) }}" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl border border-slate-200 bg-white text-slate-700 text-sm font-medium hover:bg-slate-50 hover:border-slate-300 transition self-start sm:self-auto">
            View Details
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
        </a>
    </div>
    @endif

    {{-- ===================== Main grid ===================== --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">


            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                    <div>
                        <h3 class="text-sm font-semibold text-slate-800">Recent Bookings</h3>
                        <p class="text-xs text-slate-400 mt-0.5">Your latest exam booking activity</p>
                    </div>
                    <a href="{{ route('user.bookings.index') }}" class="text-xs font-semibold text-brand-600 hover:text-brand-700 transition">View all →</a>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50/70">
                            <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500">
                                <th class="px-6 py-3 font-medium">Reference</th>
                                <th class="px-6 py-3 font-medium">Session</th>
                                <th class="px-6 py-3 font-medium">Status</th>
                                <th class="px-6 py-3 font-medium">Created</th>
                                <th class="px-6 py-3 text-right font-medium">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse ($recentActivity as $booking)
                            @php
                                $statusStyles = [
                                    'pending'    => ['bg-amber-50 text-amber-700 border-amber-200'],
                                    'processing' => ['bg-blue-50 text-blue-700 border-blue-200'],
                                    'booked'     => ['bg-emerald-50 text-emerald-700 border-emerald-200'],
                                    'failed'     => ['bg-red-50 text-red-700 border-red-200'],
                                    'cancelled'  => ['bg-slate-100 text-slate-600 border-slate-200'],
                                    'refunded'   => ['bg-purple-50 text-purple-700 border-purple-200'],
                                ];
                                $style = $statusStyles[$booking->booking_status] ?? ['bg-slate-50 text-slate-700 border-slate-200'];
                            @endphp
                            <tr class="hover:bg-slate-50/60 transition">
                                <td class="px-6 py-4 font-mono text-xs text-slate-600">{{ $booking->booking_reference ?? ('#' . $booking->id) }}</td>
                                <td class="px-6 py-4 text-xs text-slate-500">{{ $booking->exam_session_id ?? '—' }}</td>
                                <td class="px-6 py-4">
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium border {{ $style[0] }}">{{ ucfirst($booking->booking_status) }}</span>
                                </td>
                                <td class="px-6 py-4 text-xs text-slate-500">{{ $booking->created_at->diffForHumans() }}</td>
                                <td class="px-6 py-4 text-right">
                                    <a href="{{ route('user.bookings.show', $booking) }}" class="inline-flex items-center gap-1.5 text-xs font-medium text-brand-600 hover:text-brand-700 transition">
                                        View
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                    </a>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="5" class="px-6 py-12 text-center">
                                    <div class="w-12 h-12 mx-auto rounded-full bg-slate-100 text-slate-400 flex items-center justify-center mb-3">
                                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                    </div>
                                    <p class="text-sm text-slate-400">No bookings yet.</p>
                                    <a href="{{ route('user.bookings.create') }}" class="inline-block mt-3 text-xs font-semibold text-brand-600 hover:text-brand-700">Create your first booking →</a>
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>


            {{-- Recent transactions --}}
            @if ($recentTransactions->isNotEmpty())
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                    <div>
                        <h3 class="text-sm font-semibold text-slate-800">Recent Transactions</h3>
                        <p class="text-xs text-slate-400 mt-0.5">Latest wallet activity</p>
                    </div>
                    <a href="{{ route('user.wallets.index') }}" class="text-xs font-semibold text-brand-600 hover:text-brand-700 transition">Wallet →</a>
                </div>
                <div class="divide-y divide-slate-100">
                    @foreach ($recentTransactions as $tx)
                    @php
                        $txStyles = [
                            'deposit'           => ['bg-emerald-50 text-emerald-600', 'Deposit', true],
                            'booking_hold'      => ['bg-amber-50 text-amber-600', 'Booking Hold', false],
                            'booking_debit'     => ['bg-indigo-50 text-indigo-600', 'Booking Debit', false],
                            'refund'            => ['bg-sky-50 text-sky-600', 'Refund', true],
                            'manual_adjustment' => ['bg-slate-100 text-slate-600', 'Adjustment', null],
                        ];
                        [$txBadge, $txLabel, $txIn] = $txStyles[$tx->type] ?? ['bg-slate-100 text-slate-600', ucfirst($tx->type), null];
                    @endphp
                    <div class="px-6 py-3.5 flex items-center justify-between gap-4 hover:bg-slate-50/60 transition">
                        <div class="flex items-center gap-3 min-w-0">
                            <span class="w-8 h-8 shrink-0 rounded-lg {{ $txBadge }} flex items-center justify-center text-[10px] font-bold">{{ strtoupper(substr($txLabel, 0, 2)) }}</span>
                            <div class="min-w-0">
                                <p class="text-xs font-medium text-slate-700">{{ $txLabel }}</p>
                                <p class="text-[11px] text-slate-400">{{ $tx->created_at->format('M d, g:i A') }}</p>
                            </div>
                        </div>
                        <span class="text-sm font-semibold {{ $txIn === true ? 'text-emerald-600' : (($txIn === false) ? 'text-red-600' : 'text-slate-600') }}">
                            {{ $txIn === true ? '+' : ($txIn === false ? '−' : '±') }}{{ number_format((float) $tx->amount, 2) }}
                        </span>
                    </div>
                    @endforeach
                </div>
            </div>
            @endif
        </div>


        {{-- Right column --}}
        <div class="space-y-6">
            <div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-slate-900 via-indigo-950 to-fuchsia-950 p-6 text-white shadow-xl shadow-indigo-900/20">
                <div class="absolute -top-16 -right-16 w-48 h-48 bg-indigo-500/25 rounded-full blur-3xl pointer-events-none"></div>
                <div class="relative">
                    <p class="text-xs text-indigo-300 font-medium">Available Balance</p>
                    <p class="text-3xl font-extrabold mt-1 tracking-tight">{{ number_format($walletBalance, 2) }} <span class="text-sm font-medium text-slate-400">SAR</span></p>
                    <div class="grid grid-cols-2 gap-3 mt-5 pt-5 border-t border-white/10">
                        <div>
                            <p class="text-[11px] text-slate-400">Reserved</p>
                            <p class="text-sm font-bold mt-0.5">{{ number_format($reservedBalance, 2) }}</p>
                        </div>
                        <div>
                            <p class="text-[11px] text-slate-400">Credit Limit</p>
                            <p class="text-sm font-bold mt-0.5">{{ number_format($creditLimit, 2) }}</p>
                        </div>
                    </div>
                    <a href="{{ route('user.deposits.create') }}" class="mt-5 inline-flex items-center justify-center gap-2 w-full px-4 py-2.5 bg-gradient-to-r from-indigo-500 to-fuchsia-500 hover:from-indigo-600 hover:to-fuchsia-600 text-white text-sm font-semibold rounded-xl shadow-lg shadow-indigo-500/25 transition">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        Add Funds
                    </a>
                </div>
            </div>

            @if ($latestDeposit)
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
                <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">Latest Deposit</p>
                <p class="text-lg font-bold text-slate-900 mt-1">{{ number_format($latestDeposit->amount, 2) }} <span class="text-sm font-medium text-slate-400">SAR</span></p>
                <p class="text-xs text-slate-400 mt-0.5">{{ $latestDeposit->payment_method }} · {{ $latestDeposit->created_at->format('M d, Y') }}</p>
                <span class="inline-flex items-center mt-3 px-2.5 py-1 rounded-full text-xs font-medium border {{ $latestDeposit->status === 'approved' ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : ($latestDeposit->status === 'rejected' ? 'bg-red-50 text-red-700 border-red-200' : 'bg-amber-50 text-amber-700 border-amber-200') }}">{{ ucfirst($latestDeposit->status) }}</span>
            </div>
            @endif

            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
                    <div>
                        <h3 class="text-sm font-semibold text-slate-800">Notifications</h3>
                        <p class="text-xs text-slate-400 mt-0.5">{{ $unreadNotifications }} unread</p>
                    </div>
                    <a href="{{ route('user.notifications.index') }}" class="text-xs font-semibold text-brand-600 hover:text-brand-700 transition">View all →</a>
                </div>
                <div class="divide-y divide-slate-50">
                    @forelse ($notifications as $notification)
                    <div class="px-5 py-3.5 flex items-start gap-3 {{ $notification->read_at ? '' : 'bg-indigo-50/40' }}">
                        <span class="w-2.5 h-2.5 rounded-full mt-1.5 shrink-0 {{ $notification->read_at ? 'bg-slate-200' : 'bg-indigo-500' }}"></span>
                        <div class="min-w-0">
                            <p class="text-xs font-semibold {{ $notification->read_at ? 'text-slate-600' : 'text-slate-900' }} truncate">{{ $notification->title }}</p>
                            <p class="text-[11px] text-slate-500 mt-0.5 line-clamp-2">{{ $notification->body }}</p>
                            <p class="text-[10px] text-slate-400 mt-1">{{ $notification->created_at->diffForHumans() }}</p>
                        </div>
                    </div>
                    @empty
                    <div class="px-5 py-8 text-center">
                        <p class="text-xs text-slate-400">You're all caught up 🎉</p>
                    </div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

