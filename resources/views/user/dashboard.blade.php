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
                @if (Auth::user()->canCreateSelfServiceDeposit())
                    <a href="{{ route('user.deposits.create') }}" class="inline-flex items-center gap-2 px-4 py-2.5 bg-gradient-to-r from-indigo-500 to-fuchsia-500 hover:from-indigo-600 hover:to-fuchsia-600 text-white text-sm font-semibold rounded-xl shadow-lg shadow-indigo-500/25 transition">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        Add Funds
                    </a>
                @else
                    <a href="{{ route('user.deposits.index') }}" class="inline-flex items-center gap-2 px-4 py-2.5 bg-gradient-to-r from-indigo-500 to-fuchsia-500 hover:from-indigo-600 hover:to-fuchsia-600 text-white text-sm font-semibold rounded-xl shadow-lg shadow-indigo-500/25 transition">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-width="2" d="M3 12h18"/></svg>
                        Deposit History
                    </a>
                @endif
                @if (Auth::user()->hasPermission('manage agency users'))
                    <a href="{{ route('agency.users.index') }}" class="inline-flex items-center gap-2 px-4 py-2.5 bg-white/10 hover:bg-white/20 border border-white/10 text-white text-sm font-medium rounded-xl transition">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                        Agency Users
                    </a>
                @endif
            </div>
        </div>
    </div>

    {{-- ===================== Connected SVP profiles ===================== --}}
    <div id="svp-profiles" class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="px-5 sm:px-6 py-4 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <div class="flex items-center gap-2">
                    <span class="w-8 h-8 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7zM19 8v6m3-3h-6"/></svg>
                    </span>
                    <h3 class="text-sm font-semibold text-slate-800">SVP Profiles</h3>
                </div>
                <p class="text-xs text-slate-400 mt-1">Connect multiple SVP accounts and switch between encrypted sessions.</p>
            </div>
            <button type="button" data-open-svp-connect class="inline-flex items-center justify-center gap-2 px-3.5 py-2 rounded-xl bg-gradient-to-r from-indigo-500 to-fuchsia-500 hover:from-indigo-600 hover:to-fuchsia-600 text-white text-xs font-semibold shadow-sm transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                Connect another SVP account
            </button>
        </div>
        <div class="divide-y divide-slate-100">
            @forelse ($candidates as $candidate)
                <div class="px-5 sm:px-6 py-4 flex flex-col md:flex-row md:items-center justify-between gap-4 {{ $candidate->is_selected ? 'bg-indigo-50/40' : '' }}">
                    <div class="flex items-start gap-3 min-w-0">
                        <div class="w-9 h-9 shrink-0 rounded-full {{ $candidate->is_active ? 'bg-emerald-50 text-emerald-600' : 'bg-slate-100 text-slate-400' }} flex items-center justify-center text-xs font-bold">
                            {{ strtoupper(substr($candidate->full_name ?: 'S', 0, 1)) }}
                        </div>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="text-sm font-semibold text-slate-800 truncate">{{ $candidate->full_name ?: 'SVP Candidate' }}</p>
                                @if ($candidate->is_selected)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-indigo-100 text-indigo-700 text-[10px] font-bold">Selected</span>
                                @endif
                                <span class="inline-flex items-center gap-1 text-[10px] font-semibold {{ $candidate->is_active ? 'text-emerald-600' : 'text-slate-400' }}"><span class="w-1.5 h-1.5 rounded-full {{ $candidate->is_active ? 'bg-emerald-500' : 'bg-slate-300' }}"></span>{{ $candidate->is_active ? 'Active' : 'Deactive' }}</span>
                            </div>
                            <p class="text-xs text-slate-400 mt-0.5">SVP ID: {{ $candidate->svp_user_id ?: 'Not synced' }} · {{ $candidate->is_connected ? 'Encrypted session ready' : 'Reconnect required' }}</p>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 md:justify-end">
                        @if ($candidate->is_active && $candidate->is_connected && ! $candidate->is_selected)
                            <form method="POST" action="{{ route('user.svp-profiles.switch', $candidate) }}">
                                @csrf
                                <button type="submit" class="px-3 py-2 rounded-lg border border-indigo-200 bg-indigo-50 text-indigo-700 text-xs font-semibold hover:bg-indigo-100 transition">Switch to this profile</button>
                            </form>
                        @elseif ($candidate->is_active && ! $candidate->is_connected)
                            <button type="button" data-open-svp-connect class="px-3 py-2 rounded-lg border border-amber-200 bg-amber-50 text-amber-700 text-xs font-semibold hover:bg-amber-100 transition">Reconnect</button>
                        @elseif (! $candidate->is_active)
                            <form method="POST" action="{{ route('user.svp-profiles.activate', $candidate) }}">
                                @csrf
                                <button type="submit" class="px-3 py-2 rounded-lg border border-emerald-200 bg-emerald-50 text-emerald-700 text-xs font-semibold hover:bg-emerald-100 transition">Activate</button>
                            </form>
                        @endif
                        @if ($candidate->is_active)
                            <form method="POST" action="{{ route('user.svp-profiles.deactivate', $candidate) }}">
                                @csrf
                                <button type="submit" class="px-3 py-2 rounded-lg border border-slate-200 bg-white text-slate-600 text-xs font-semibold hover:border-red-200 hover:bg-red-50 hover:text-red-700 transition">Deactivate</button>
                            </form>
                        @endif
                        <form method="POST" action="{{ route('user.svp-profiles.destroy', $candidate) }}" onsubmit="return confirm('Delete this SVP profile and its encrypted session? Existing booking history will be kept.');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg border border-red-200 bg-red-50 text-red-700 text-xs font-semibold hover:bg-red-100 hover:border-red-300 transition">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3m-8 0h10"/></svg>
                                Delete profile
                            </button>
                        </form>
                    </div>
                </div>
            @empty
                <div class="px-5 sm:px-6 py-6 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <p class="text-sm text-slate-500">No SVP profiles connected yet.</p>
                    <button type="button" data-open-svp-connect class="text-xs font-semibold text-brand-600 hover:text-brand-700">Connect your first account →</button>
                </div>
            @endforelse
        </div>
    </div>

    {{-- ===================== Inline SVP connect modal ===================== --}}
    <div id="svp-connect-modal" class="fixed inset-0 z-[70] hidden items-center justify-center overflow-y-auto bg-slate-950/60 p-3 backdrop-blur-sm sm:p-4" role="dialog" aria-modal="true" aria-labelledby="svp-connect-title">
        <div class="my-auto w-full max-w-md max-h-[92vh] overflow-y-auto rounded-2xl bg-white shadow-2xl" data-svp-modal-card>
            <div class="flex items-start justify-between border-b border-slate-100 px-4 py-3 sm:px-5">
                <div>
                    <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-indigo-500">SVP Profiles</p>
                    <h3 id="svp-connect-title" class="mt-0.5 text-base font-bold text-slate-900">Connect another SVP account</h3>
                    <p class="mt-0.5 text-[11px] text-slate-500">Add the account here without leaving your dashboard.</p>
                </div>
                <button type="button" data-close-svp-connect class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label="Close">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <div class="px-4 py-4 sm:px-5">
                <div id="svp-connect-status" class="mb-3 hidden rounded-xl border px-3 py-2 text-xs" role="status"></div>

                <form id="svp-inline-login-form" class="space-y-3">
                    @csrf
                    <div>
                        <label for="svp-inline-email" class="mb-1 block text-xs font-semibold text-slate-700">SVP email</label>
                        <input id="svp-inline-email" name="email" type="email" required autocomplete="email" class="w-full rounded-xl border-slate-200 px-3 py-2.5 text-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="you@example.com">
                    </div>
                    <div>
                        <label for="svp-inline-password" class="mb-1 block text-xs font-semibold text-slate-700">SVP password</label>
                        <input id="svp-inline-password" name="password" type="password" required autocomplete="current-password" class="w-full rounded-xl border-slate-200 px-3 py-2.5 text-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="••••••••">
                    </div>
                    <div>
                        <label for="svp-inline-otp-method" class="mb-1 block text-xs font-semibold text-slate-700">OTP delivery</label>
                        <select id="svp-inline-otp-method" name="otp_method" class="w-full rounded-xl border-slate-200 px-3 py-2.5 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="email">Email</option>
                            <option value="sms">SMS</option>
                        </select>
                    </div>
                    <button id="svp-inline-login-submit" type="submit" class="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-indigo-500 to-fuchsia-500 px-4 py-2.5 text-sm font-semibold text-white transition hover:from-indigo-600 hover:to-fuchsia-600 disabled:cursor-not-allowed disabled:opacity-60">
                        <span data-svp-submit-label>Send OTP</span>
                        <span data-svp-spinner class="hidden h-4 w-4 animate-spin rounded-full border-2 border-white/40 border-t-white"></span>
                    </button>
                </form>

                <form id="svp-inline-otp-form" class="hidden space-y-3">
                    @csrf
                    <p class="rounded-xl bg-indigo-50 px-3 py-2.5 text-xs text-indigo-800">Enter the OTP sent to <strong id="svp-inline-otp-email"></strong>.</p>
                    <div>
                        <label for="svp-inline-otp-code" class="mb-1 block text-xs font-semibold text-slate-700">One-time passcode</label>
                        <input id="svp-inline-otp-code" name="otp_code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="8" required class="w-full rounded-xl border-slate-200 px-3 py-2.5 text-center text-lg font-bold tracking-[0.35em] focus:border-indigo-500 focus:ring-indigo-500" placeholder="000000">
                    </div>
                    <button id="svp-inline-otp-submit" type="submit" class="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-indigo-500 to-fuchsia-500 px-4 py-2.5 text-sm font-semibold text-white transition hover:from-indigo-600 hover:to-fuchsia-600 disabled:cursor-not-allowed disabled:opacity-60">
                        <span data-svp-verify-label>Verify and connect</span>
                        <span data-svp-verify-spinner class="hidden h-4 w-4 animate-spin rounded-full border-2 border-white/40 border-t-white"></span>
                    </button>
                    <div class="flex items-center justify-between text-xs">
                        <button type="button" id="svp-inline-resend" class="font-semibold text-indigo-600 hover:text-indigo-800">Resend OTP</button>
                        <button type="button" id="svp-inline-change-account" class="text-slate-500 hover:text-slate-700">Use another account</button>
                    </div>
                </form>
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
                <p class="text-xl font-bold text-slate-900 mt-0.5">{{ number_format($walletBalance, 2) }} <span class="text-sm font-medium text-slate-400">BDT</span></p>
                <p class="text-xs text-slate-400 mt-0.5 truncate">Credit limit: {{ number_format($creditLimit, 2) }}</p>
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
                    <p class="text-xs text-indigo-300 font-medium">Wallet Balance</p>
                    <p class="text-3xl font-extrabold mt-1 tracking-tight">{{ number_format($walletBalance, 2) }} <span class="text-sm font-medium text-slate-400">BDT</span></p>
                    <div class="grid grid-cols-1 gap-3 mt-5 pt-5 border-t border-white/10">
                        <div>
                            <p class="text-[11px] text-slate-400">Credit Limit</p>
                            <p class="text-sm font-bold mt-0.5">{{ number_format($creditLimit, 2) }}</p>
                        </div>
                    </div>
                    <a href="{{ Auth::user()->canCreateSelfServiceDeposit() ? route('user.deposits.create') : route('user.deposits.index') }}" class="mt-5 inline-flex items-center justify-center gap-2 w-full px-4 py-2.5 bg-gradient-to-r from-indigo-500 to-fuchsia-500 hover:from-indigo-600 hover:to-fuchsia-600 text-white text-sm font-semibold rounded-xl shadow-lg shadow-indigo-500/25 transition">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        {{ Auth::user()->canCreateSelfServiceDeposit() ? 'Add Funds' : 'Deposit History' }}
                    </a>
                </div>
            </div>

            @if ($latestDeposit)
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
                <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">Latest Deposit</p>
                <p class="text-lg font-bold text-slate-900 mt-1">{{ number_format($latestDeposit->amount, 2) }} <span class="text-sm font-medium text-slate-400">BDT</span></p>
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

<script>
(() => {
    const modal = document.getElementById('svp-connect-modal');
    const loginForm = document.getElementById('svp-inline-login-form');
    const otpForm = document.getElementById('svp-inline-otp-form');
    if (!modal || !loginForm || !otpForm) return;

    const statusBox = document.getElementById('svp-connect-status');
    const otpEmail = document.getElementById('svp-inline-otp-email');
    const otpCode = document.getElementById('svp-inline-otp-code');
    const loginSubmit = document.getElementById('svp-inline-login-submit');
    const otpSubmit = document.getElementById('svp-inline-otp-submit');
    const resendButton = document.getElementById('svp-inline-resend');
    const changeAccountButton = document.getElementById('svp-inline-change-account');

    const routes = {
        login: @json(route('svp.login.attempt')),
        verify: @json(route('svp.otp.verify')),
        resend: @json(route('svp.otp.resend')),
    };

    const setStatus = (message, type = 'info') => {
        if (!message) {
            statusBox.textContent = '';
            statusBox.className = 'mb-4 hidden rounded-xl border px-3 py-2.5 text-sm';
            return;
        }

        const palette = type === 'error'
            ? 'border-red-200 bg-red-50 text-red-700'
            : type === 'success'
                ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                : 'border-indigo-200 bg-indigo-50 text-indigo-700';
        statusBox.textContent = message;
        statusBox.className = `mb-4 rounded-xl border px-3 py-2.5 text-sm ${palette}`;
    };

    const errorMessage = (body, fallback) => {
        const validationMessage = body?.errors
            ? Object.values(body.errors).flat().find(Boolean)
            : null;
        return validationMessage || body?.message || fallback;
    };

    const setBusy = (button, busy, labelSelector, spinnerSelector, label) => {
        button.disabled = busy;
        button.querySelector(labelSelector).textContent = busy ? 'Please wait…' : label;
        button.querySelector(spinnerSelector).classList.toggle('hidden', !busy);
    };

    const postForm = async (url, form) => {
        const response = await fetch(url, {
            method: 'POST',
            body: new FormData(form),
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });
        const body = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(errorMessage(body, 'SVP authentication failed. Please try again.'));
        return body;
    };

    const showLogin = () => {
        loginForm.classList.remove('hidden');
        otpForm.classList.add('hidden');
        otpCode.value = '';
        setStatus('');
        window.setTimeout(() => document.getElementById('svp-inline-email')?.focus(), 50);
    };

    const showOtp = (email) => {
        loginForm.classList.add('hidden');
        otpForm.classList.remove('hidden');
        otpEmail.textContent = email || 'your email';
        setStatus('OTP sent. Check your email and enter the code below.', 'success');
        window.setTimeout(() => otpCode.focus(), 50);
    };

    const openModal = () => {
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        showLogin();
    };

    const closeModal = () => {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    };

    document.querySelectorAll('[data-open-svp-connect]').forEach((button) => button.addEventListener('click', openModal));
    document.querySelectorAll('[data-close-svp-connect]').forEach((button) => button.addEventListener('click', closeModal));
    modal.addEventListener('click', (event) => {
        if (event.target === modal) closeModal();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !modal.classList.contains('hidden')) closeModal();
    });

    loginForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        setStatus('');
        setBusy(loginSubmit, true, '[data-svp-submit-label]', '[data-svp-spinner]', 'Send OTP');
        try {
            const body = await postForm(routes.login, loginForm);
            if (body.status === 'authenticated') {
                setStatus('SVP account connected. Refreshing your profiles…', 'success');
                window.setTimeout(() => window.location.reload(), 500);
                return;
            }
            showOtp(body.email || document.getElementById('svp-inline-email').value);
        } catch (error) {
            setStatus(error.message, 'error');
        } finally {
            setBusy(loginSubmit, false, '[data-svp-submit-label]', '[data-svp-spinner]', 'Send OTP');
        }
    });

    otpForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        setStatus('');
        setBusy(otpSubmit, true, '[data-svp-verify-label]', '[data-svp-verify-spinner]', 'Verify and connect');
        try {
            const body = await postForm(routes.verify, otpForm);
            if (body.status === 'authenticated') {
                setStatus('SVP account connected. Refreshing your profiles…', 'success');
                window.setTimeout(() => window.location.reload(), 500);
            } else {
                showLogin();
            }
        } catch (error) {
            setStatus(error.message, 'error');
        } finally {
            setBusy(otpSubmit, false, '[data-svp-verify-label]', '[data-svp-verify-spinner]', 'Verify and connect');
        }
    });

    resendButton.addEventListener('click', async () => {
        resendButton.disabled = true;
        try {
            const body = await postForm(routes.resend, otpForm);
            setStatus(body.message || 'A new OTP has been sent.', 'success');
        } catch (error) {
            setStatus(error.message, 'error');
        } finally {
            resendButton.disabled = false;
        }
    });

    changeAccountButton.addEventListener('click', showLogin);
})();
</script>
@endsection
