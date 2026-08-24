@extends('layouts.panel')

@section('title', 'Wallets')
@section('page-title', 'Agency Wallets')

@section('content')
<div class="mb-6">
    <h2 class="text-xl font-bold text-slate-900">Agency Wallets</h2>
    <p class="text-sm text-slate-500 mt-0.5">Balances and credit limits for all agency wallets.</p>
</div>

<div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
        <div>
            <h3 class="text-sm font-semibold text-slate-700">All Wallets</h3>
            <p class="text-xs text-slate-400 mt-0.5">{{ $wallets->total() }} total</p>
        </div>
        <span class="text-xs font-medium bg-slate-100 text-slate-600 px-3 py-1.5 rounded-full">Wallets</span>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-left">
                <tr>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Agency</th>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Wallet Balance</th>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Credit Limit</th>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($wallets as $wallet)
                    <tr class="hover:bg-slate-50/50 transition">
                        <td class="px-6 py-4 font-medium text-slate-700">{{ $wallet->agency?->name ?? 'N/A' }}</td>
                        <td class="px-6 py-4 font-bold text-emerald-600">{{ number_format($wallet->available_balance, 2) }}</td>
                        <td class="px-6 py-4 text-slate-600">{{ number_format($wallet->credit_limit, 2) }}</td>
                        <td class="px-6 py-4">
                            <a href="{{ route('admin.wallets.show', $wallet->agency) }}" class="text-xs font-medium text-brand-600 hover:text-brand-700 transition">View</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-6 py-12 text-center">
                            <p class="text-sm text-slate-400">No wallets yet.</p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="px-6 py-4 border-t border-slate-100">
        {{ $wallets->links() }}
    </div>
</div>
@endsection
