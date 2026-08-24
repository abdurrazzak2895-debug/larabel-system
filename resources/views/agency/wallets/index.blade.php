@extends('layouts.panel')

@section('title', 'Agency Wallets')
@section('page-title', 'Agency Wallet')

@section('content')
{{-- Hero --}}
<div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-slate-900 via-indigo-900 to-fuchsia-900 p-6 sm:p-8 text-white shadow-xl shadow-indigo-500/10 mb-6">
    <div class="absolute -top-24 -right-24 w-72 h-72 bg-fuchsia-500/20 rounded-full blur-3xl"></div>
    <div class="absolute -bottom-24 -left-16 w-72 h-72 bg-indigo-500/20 rounded-full blur-3xl"></div>
    <div class="relative">
        <p class="text-xs font-semibold uppercase tracking-widest text-indigo-300 mb-2">Wallet Balance</p>
        <p class="text-4xl sm:text-5xl font-black tracking-tight">
            {{ number_format($wallet?->available_balance ?? 0, 2) }}
            <span class="text-lg sm:text-xl font-bold text-indigo-300">BDT</span>
        </p>
    </div>
</div>

{{-- Summary cards --}}
<div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-8">
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
        <p class="text-xs font-medium text-slate-400 uppercase tracking-wide">Credit Limit</p>
        <p class="text-2xl font-bold text-slate-900 mt-1">{{ number_format($wallet?->credit_limit ?? 0, 2) }} <span class="text-sm font-medium text-slate-500">BDT</span></p>
    </div>
</div>

{{-- Ledger --}}
<div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
        <div>
            <h3 class="text-sm font-semibold text-slate-700">Transaction Ledger</h3>
            <p class="text-xs text-slate-400 mt-0.5">{{ $transactions->total() }} transactions</p>
        </div>
        <span class="text-xs font-medium bg-slate-100 text-slate-600 px-3 py-1.5 rounded-full">Wallet</span>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-left">
                <tr>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">ID</th>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Type</th>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Amount</th>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Reference</th>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Date</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($transactions as $txn)
                    @php
                        $credit = in_array(strtolower((string) $txn->type), ['credit', 'deposit', 'refund', 'adjustment'], true);
                        $signed = $credit ? '+ ' . number_format(abs((float) $txn->amount), 2) : '- ' . number_format(abs((float) $txn->amount), 2);
                    @endphp
                    <tr class="hover:bg-slate-50/50 transition">
                        <td class="px-6 py-4 font-mono text-xs text-slate-500">#{{ $txn->id }}</td>
                        <td class="px-6 py-4">
                            <span class="inline-flex px-2 py-0.5 rounded text-xs font-medium border {{ $credit ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-rose-50 text-rose-700 border-rose-200' }}">
                                {{ $txn->type }}
                            </span>
                        </td>
                        <td class="px-6 py-4 font-semibold {{ $credit ? 'text-emerald-600' : 'text-rose-600' }}">{{ $signed }}</td>
                        <td class="px-6 py-4 font-mono text-xs text-slate-500">{{ $txn->reference ?? '—' }}</td>
                        <td class="px-6 py-4 text-slate-500">{{ $txn->created_at?->format('d M Y H:i') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-6 py-12 text-center">
                            <div class="flex flex-col items-center gap-3">
                                <div class="w-12 h-12 rounded-full bg-slate-100 flex items-center justify-center">
                                    <svg class="w-6 h-6 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h2m-5-7h16a1 1 0 011 1v10a1 1 0 01-1 1H5a2 2 0 01-2-2V7a2 2 0 012-2z"/></svg>
                                </div>
                                <p class="text-sm text-slate-400">No transactions yet.</p>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="px-6 py-4 border-t border-slate-100">
        {{ $transactions->links() }}
    </div>
</div>
@endsection
