@extends('layouts.user')

@section('title', 'Wallet')
@section('page-title', 'Wallet')

@section('content')
<div class="space-y-6">

    {{-- ===================== Page header ===================== --}}
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Wallet</h1>
            <p class="text-sm text-slate-500 mt-1">Your personal booking balance and transaction history. The agency wallet is not used.</p>
        </div>
        <a href="{{ route('user.deposits.index') }}" class="inline-flex items-center justify-center gap-2 px-4 py-2.5 bg-gradient-to-r from-indigo-500 to-fuchsia-500 hover:from-indigo-600 hover:to-fuchsia-600 text-white text-sm font-semibold rounded-xl shadow-lg shadow-indigo-500/25 transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            Deposit History
        </a>
    </div>

    {{-- ===================== Balance hero ===================== --}}
    <div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-slate-900 via-indigo-950 to-fuchsia-950 p-6 sm:p-8 text-white shadow-xl shadow-indigo-900/20">
        <div class="absolute -top-20 -right-20 w-64 h-64 bg-indigo-500/25 rounded-full blur-3xl pointer-events-none"></div>
        <div class="absolute -bottom-24 -left-16 w-56 h-56 bg-fuchsia-500/20 rounded-full blur-3xl pointer-events-none"></div>
        <div class="relative">
            <div class="flex items-center justify-between">
                <p class="text-sm text-indigo-300 font-medium">Personal Wallet Balance</p>
                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-white/10 border border-white/10 text-xs text-indigo-200">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    BDT
                </span>
            </div>
            <p class="text-4xl sm:text-5xl font-extrabold mt-2 tracking-tight">{{ number_format($balance, 2) }}</p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-6 pt-6 border-t border-white/10">
                <div>
                    <p class="text-xs text-slate-400">Credit Limit</p>
                    <p class="text-lg font-bold mt-0.5">{{ number_format($creditLimit, 2) }}</p>
                </div>
                <div>
                    <p class="text-xs text-slate-400">Spendable + Credit</p>
                    <p class="text-lg font-bold mt-0.5">{{ number_format($balance + $creditLimit, 2) }}</p>
                </div>
            </div>
        </div>
    </div>

    {{-- ===================== Summary cards ===================== --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 flex items-center gap-4">
            <div class="w-11 h-11 shrink-0 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <div>
                <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">Total Deposits</p>
                <p class="text-lg font-bold text-slate-900 mt-0.5">{{ number_format($totals['deposit'], 2) }}</p>
            </div>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 flex items-center gap-4">
            <div class="w-11 h-11 shrink-0 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5h7a2 2 0 012 2v12a2 2 0 01-2 2H7a2 2 0 01-2-2V7a2 2 0 012-2h2m2 4h4m-4 4h4m-4 4h4"/></svg>
            </div>
            <div>
                <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">Booking Spend</p>
                <p class="text-lg font-bold text-slate-900 mt-0.5">{{ number_format($totals['booking_debit'], 2) }}</p>
            </div>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 flex items-center gap-4">
            <div class="w-11 h-11 shrink-0 rounded-xl bg-sky-50 text-sky-600 flex items-center justify-center">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
            </div>
            <div>
                <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">Total Refunds</p>
                <p class="text-lg font-bold text-slate-900 mt-0.5">{{ number_format($totals['refund'], 2) }}</p>
            </div>
        </div>
    </div>

    {{-- ===================== Transactions table ===================== --}}
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100">
            <h3 class="text-sm font-semibold text-slate-800">Transaction History</h3>
            <p class="text-xs text-slate-400 mt-0.5">All wallet ledger entries</p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50/70">
                    <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500">
                        <th class="px-6 py-3 font-medium">Type</th>
                        <th class="px-6 py-3 font-medium">Reference</th>
                        <th class="px-6 py-3 font-medium">Date</th>
                        <th class="px-6 py-3 text-right font-medium">Amount</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($transactions as $transaction)
                    @php
                        $txStyles = [
                            'deposit'           => ['bg-emerald-50 text-emerald-600', 'Deposit', true],
                            'booking_hold'      => ['bg-amber-50 text-amber-600', 'Booking Hold', false],
                            'booking_debit'     => ['bg-indigo-50 text-indigo-600', 'Booking Debit', false],
                            'refund'            => ['bg-sky-50 text-sky-600', 'Refund', true],
                            'manual_adjustment' => ['bg-slate-100 text-slate-600', 'Adjustment', null],
                        ];
                        [$txBadge, $txLabel, $txIn] = $txStyles[$transaction->type] ?? ['bg-slate-100 text-slate-600', ucfirst($transaction->type), null];
                        $amount = (float) $transaction->amount;
                    @endphp
                    <tr class="hover:bg-slate-50/60 transition">
                        <td class="px-6 py-4">
                            <div class="flex items-center gap-3">
                                <span class="w-9 h-9 shrink-0 rounded-lg {{ $txBadge }} flex items-center justify-center text-xs font-bold">{{ strtoupper(substr($txLabel, 0, 2)) }}</span>
                                <span class="text-xs font-medium text-slate-700">{{ $txLabel }}</span>
                            </div>
                        </td>
                        <td class="px-6 py-4 font-mono text-xs text-slate-500">{{ $transaction->reference ?? '—' }}</td>
                        <td class="px-6 py-4 text-xs text-slate-500">{{ $transaction->created_at->format('M d, Y g:i A') }}</td>
                        <td class="px-6 py-4 text-right text-sm font-semibold {{ ($txIn === true) ? 'text-emerald-600' : (($txIn === false) ? 'text-red-600' : 'text-slate-600') }}">
                            {{ $txIn === true ? '+' : ($txIn === false ? '−' : '±') }}{{ number_format($amount, 2) }}
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="4" class="px-6 py-16 text-center">
                            <div class="w-14 h-14 mx-auto rounded-full bg-slate-100 text-slate-400 flex items-center justify-center mb-4">
                                <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h2m-5-7h16a1 1 0 011 1v10a1 1 0 01-1 1H5a2 2 0 01-2-2V7a2 2 0 012-2z"/></svg>
                            </div>
                            <p class="text-sm font-medium text-slate-600">No transactions yet</p>
                            <p class="text-xs text-slate-400 mt-1">Your wallet activity will appear here.</p>
                            <a href="{{ route('user.deposits.index') }}" class="inline-block mt-4 px-4 py-2.5 rounded-xl bg-gradient-to-r from-indigo-500 to-fuchsia-500 text-white text-sm font-semibold shadow-lg shadow-indigo-500/25 hover:from-indigo-600 hover:to-fuchsia-600 transition">View Deposit History</a>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($transactions instanceof \Illuminate\Pagination\AbstractPaginator && $transactions->hasPages())
        <div class="px-6 py-4 border-t border-slate-100">
            {{ $transactions->links() }}
        </div>
        @endif
    </div>
</div>
@endsection

