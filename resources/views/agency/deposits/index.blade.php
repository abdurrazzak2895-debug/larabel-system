@extends('layouts.panel')

@section('title', 'Agency Deposits')
@section('page-title', 'Deposit Requests')

@section('content')
<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-xl font-bold text-slate-900">Deposit Requests</h2>
        <p class="text-sm text-slate-500 mt-0.5">Manage deposits for users in your agency. User bookings use personal wallets, not the agency wallet.</p>
    </div>
    <a href="{{ route('agency.deposits.create') }}"
       class="inline-flex items-center gap-2 px-4 py-2.5 bg-gradient-to-r from-indigo-500 to-fuchsia-500 hover:from-indigo-600 hover:to-fuchsia-600 text-white text-sm font-semibold rounded-xl shadow-lg shadow-indigo-500/20 transition">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
        New Deposit
    </a>
</div>

<div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
        <div>
            <h3 class="text-sm font-semibold text-slate-700">Deposit History</h3>
            <p class="text-xs text-slate-400 mt-0.5">{{ $deposits->total() }} total</p>
        </div>
        <span class="text-xs font-medium bg-slate-100 text-slate-600 px-3 py-1.5 rounded-full">Wallet Top-ups</span>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-left">
                <tr>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">ID</th>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">User</th>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Amount</th>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Method</th>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Status</th>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Receipt</th>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Date</th>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($deposits as $deposit)
                    @php
                        $statusColors = [
                            'pending'  => 'bg-amber-50 text-amber-700 border-amber-200',
                            'approved' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                            'rejected' => 'bg-red-50 text-red-700 border-red-200',
                        ];
                        $color = $statusColors[$deposit->status] ?? 'bg-slate-50 text-slate-600 border-slate-200';
                    @endphp
                    <tr class="hover:bg-slate-50/50 transition">
                        <td class="px-6 py-4 font-mono text-xs text-slate-500">#{{ $deposit->id }}</td>
                        <td class="px-6 py-4 text-slate-600">{{ $deposit->user?->name ?? 'Legacy agency wallet' }}</td>
                        <td class="px-6 py-4 font-bold text-slate-900">{{ number_format($deposit->amount, 2) }} <span class="text-xs font-medium text-slate-400">BDT</span></td>
                        <td class="px-6 py-4 text-slate-600">{{ $deposit->payment_method }}</td>
                        <td class="px-6 py-4">
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium border {{ $color }}">
                                <span class="w-1.5 h-1.5 rounded-full {{ $deposit->status === 'approved' ? 'bg-emerald-500' : ($deposit->status === 'pending' ? 'bg-amber-500' : 'bg-red-500') }}"></span>
                                {{ ucfirst($deposit->status) }}
                            </span>
                        </td>
                        <td class="px-6 py-4">
                            @if ($deposit->receipt_path)
                                <a href="{{ asset('storage/' . $deposit->receipt_path) }}" target="_blank"
                                   class="inline-flex items-center gap-1 text-brand-600 hover:text-brand-700 text-xs font-medium transition">
                                    View Receipt
                                </a>
                            @else
                                <span class="text-xs text-slate-400">—</span>
                            @endif
                        </td>
                        <td class="px-6 py-4 text-slate-500">{{ $deposit->created_at?->format('d M Y H:i') }}</td>
                        <td class="px-6 py-4">
                            @if ($deposit->status === 'pending' && $deposit->user_id)
                                <div class="flex items-center gap-2">
                                    <form method="POST" action="{{ route('agency.deposits.approve', $deposit) }}">@csrf<button class="text-xs font-semibold text-emerald-600 hover:text-emerald-700">Approve</button></form>
                                    <form method="POST" action="{{ route('agency.deposits.reject', $deposit) }}">@csrf<button class="text-xs font-semibold text-red-600 hover:text-red-700">Reject</button></form>
                                </div>
                            @else
                                <span class="text-xs text-slate-400">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-6 py-12 text-center">
                            <div class="flex flex-col items-center gap-3">
                                <div class="w-12 h-12 rounded-full bg-slate-100 flex items-center justify-center">
                                    <svg class="w-6 h-6 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                </div>
                                <p class="text-sm text-slate-400">No deposit requests yet.</p>
                                <a href="{{ route('agency.deposits.create') }}" class="text-xs font-medium text-brand-600 hover:text-brand-700">Submit your first deposit →</a>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="px-6 py-4 border-t border-slate-100">
        {{ $deposits->links() }}
    </div>
</div>
@endsection
