@extends('layouts.panel')

@section('title', 'Wallet Ledger')
@section('page-title', 'Wallet Ledger')

@section('content')
<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-xl font-bold text-slate-900">Transaction Ledger</h2>
        <p class="text-sm text-slate-500 mt-0.5">Complete wallet transaction history for your agency.</p>
    </div>
    <a href="{{ route('agency.wallets.index') }}"
       class="inline-flex items-center gap-2 px-4 py-2.5 bg-white border border-slate-200 text-slate-700 text-sm font-medium rounded-xl hover:bg-slate-50 transition">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
        Back to Wallet
    </a>
</div>

<div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
        <div>
            <h3 class="text-sm font-semibold text-slate-700">All Transactions</h3>
            <p class="text-xs text-slate-400 mt-0.5">{{ $transactions->total() }} total</p>
        </div>
        <span class="text-xs font-medium bg-slate-100 text-slate-600 px-3 py-1.5 rounded-full">Ledger</span>
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
                    @endphp
                    <tr class="hover:bg-slate-50/50 transition">
                        <td class="px-6 py-4 font-mono text-xs text-slate-500">#{{ $txn->id }}</td>
                        <td class="px-6 py-4">
                            <span class="inline-flex px-2 py-0.5 rounded text-xs font-medium border {{ $credit ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-rose-50 text-rose-700 border-rose-200' }}">
                                {{ $txn->type }}
                            </span>
                        </td>
                        <td class="px-6 py-4 font-semibold {{ $credit ? 'text-emerald-600' : 'text-rose-600' }}">
                            {{ $credit ? '+' : '-' }} {{ number_format(abs((float) $txn->amount), 2) }}
                        </td>
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
