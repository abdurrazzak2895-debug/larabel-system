@extends('layouts.panel')

@section('title', 'Wallet Detail')
@section('page-title', 'Agency Wallet')

@section('content')
<div class="flex items-center gap-3 mb-6">
    <a href="{{ route('admin.wallets.index') }}" class="text-slate-400 hover:text-slate-600 transition">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
    </a>
    <div>
        <h2 class="text-xl font-bold text-slate-900">{{ $wallet?->agency?->name ?? 'Agency' }} — Wallet</h2>
        <p class="text-sm text-slate-500 mt-0.5">Adjust balances, freeze credit, and review transactions.</p>
    </div>
</div>

{{-- Hero --}}
<div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-slate-900 via-indigo-900 to-fuchsia-900 p-6 sm:p-8 text-white shadow-xl shadow-indigo-500/10 mb-6">
    <div class="absolute -top-24 -right-24 w-72 h-72 bg-fuchsia-500/20 rounded-full blur-3xl"></div>
    <div class="relative">
        <p class="text-xs font-semibold uppercase tracking-widest text-indigo-300 mb-2">Wallet Balance</p>
        <p class="text-4xl sm:text-5xl font-black tracking-tight">
            {{ number_format($wallet?->available_balance ?? 0, 2) }}
            <span class="text-lg sm:text-xl font-bold text-indigo-300">BDT</span>
        </p>
        <div class="flex flex-wrap gap-4 mt-4 text-sm">
            <span class="inline-flex items-center gap-1.5 text-slate-300">
                <span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span>
                Credit Limit {{ number_format($wallet?->credit_limit ?? 0, 2) }}
            </span>
        </div>
    </div>
</div>

{{-- Actions --}}
@if ($wallet)
<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
    <form method="POST" action="{{ route('admin.wallets.credit', $wallet->agency) }}" class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 space-y-3">
        @csrf
        <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">Credit Wallet</p>
        <div class="flex gap-2">
            <input type="number" name="amount" step="0.01" min="0.01" required placeholder="0.00"
                class="flex-1 rounded-xl border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
            <input type="text" name="reference" placeholder="Ref (optional)"
                class="flex-1 rounded-xl border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
        </div>
        <button type="submit" class="w-full px-4 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold rounded-xl transition">Credit</button>
    </form>

    <form method="POST" action="{{ route('admin.wallets.debit', $wallet->agency) }}" class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 space-y-3">
        @csrf
        <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">Debit Wallet</p>
        <div class="flex gap-2">
            <input type="number" name="amount" step="0.01" min="0.01" required placeholder="0.00"
                class="flex-1 rounded-xl border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
            <input type="text" name="reference" placeholder="Ref (optional)"
                class="flex-1 rounded-xl border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
        </div>
        <button type="submit" class="w-full px-4 py-2.5 bg-rose-600 hover:bg-rose-700 text-white text-sm font-semibold rounded-xl transition">Debit</button>
    </form>

    <form method="POST" action="{{ route('admin.wallets.freeze', $wallet->agency) }}" class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 space-y-3"
          onsubmit="return confirm('Freeze this wallet (set credit limit to 0)?')">
        @csrf
        <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">Freeze Credit</p>
        <p class="text-xs text-slate-500">Set the agency's credit limit to zero.</p>
        <button type="submit" class="w-full px-4 py-2.5 bg-slate-800 hover:bg-slate-900 text-white text-sm font-semibold rounded-xl transition">Freeze Wallet</button>
    </form>
</div>
@endif

{{-- Transactions --}}
<div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
        <div>
            <h3 class="text-sm font-semibold text-slate-700">Transaction Ledger</h3>
            <p class="text-xs text-slate-400 mt-0.5">{{ $transactions->total() }} transactions</p>
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
                            <span class="inline-flex px-2 py-0.5 rounded text-xs font-medium border {{ $credit ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-rose-50 text-rose-700 border-rose-200' }}">{{ $txn->type }}</span>
                        </td>
                        <td class="px-6 py-4 font-semibold {{ $credit ? 'text-emerald-600' : 'text-rose-600' }}">{{ $credit ? '+' : '-' }} {{ number_format(abs((float) $txn->amount), 2) }}</td>
                        <td class="px-6 py-4 font-mono text-xs text-slate-500">{{ $txn->reference ?? '—' }}</td>
                        <td class="px-6 py-4 text-slate-500">{{ $txn->created_at?->format('d M Y H:i') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-6 py-12 text-center">
                            <p class="text-sm text-slate-400">No transactions yet.</p>
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
