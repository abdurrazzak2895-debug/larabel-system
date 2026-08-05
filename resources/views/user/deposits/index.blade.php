@extends('layouts.user')

@section('title', 'Deposits')
@section('page-title', 'Deposits')

@section('content')
<div class="space-y-6">
    {{-- ===================== Page header ===================== --}}
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Deposits</h1>
            <p class="text-sm text-slate-500 mt-1">Track and manage your deposit requests.</p>
        </div>
        <a href="{{ route('user.deposits.create') }}" class="inline-flex items-center justify-center gap-2 px-4 py-2.5 bg-gradient-to-r from-indigo-500 to-fuchsia-500 hover:from-indigo-600 hover:to-fuchsia-600 text-white text-sm font-semibold rounded-xl shadow-lg shadow-indigo-500/25 transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            New Deposit
        </a>
    </div>

    {{-- ===================== Summary cards ===================== --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 flex items-center gap-4">
            <div class="w-11 h-11 shrink-0 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <div>
                <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">Total Deposited</p>
                <p class="text-lg font-bold text-slate-900 mt-0.5">{{ number_format($totalDeposited, 2) }} SAR</p>
            </div>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 flex items-center gap-4">
            <div class="w-11 h-11 shrink-0 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <div>
                <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">Approved</p>
                <p class="text-lg font-bold text-slate-900 mt-0.5">{{ $approvedCount }}</p>
            </div>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 flex items-center gap-4">
            <div class="w-11 h-11 shrink-0 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <div>
                <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">Pending</p>
                <p class="text-lg font-bold text-slate-900 mt-0.5">{{ $pendingCount }}</p>
            </div>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 flex items-center gap-4">
            <div class="w-11 h-11 shrink-0 rounded-xl bg-red-50 text-red-600 flex items-center justify-center">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </div>
            <div>
                <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">Rejected</p>
                <p class="text-lg font-bold text-slate-900 mt-0.5">{{ $rejectedCount }}</p>
            </div>
        </div>
    </div>

    {{-- ===================== Deposits table ===================== --}}
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100">
            <h3 class="text-sm font-semibold text-slate-800">Deposit History</h3>
            <p class="text-xs text-slate-400 mt-0.5">Current wallet balance: <span class="font-semibold text-slate-700">{{ number_format($walletBalance, 2) }} SAR</span></p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50/70">
                    <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500">
                        <th class="px-6 py-3 font-medium">ID</th>
                        <th class="px-6 py-3 font-medium">Amount</th>
                        <th class="px-6 py-3 font-medium">Method</th>
                        <th class="px-6 py-3 font-medium">Date</th>
                        <th class="px-6 py-3 font-medium">Status</th>
                        <th class="px-6 py-3 font-medium">Receipt</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($deposits as $deposit)
                    @php
                        $statusStyles = [
                            'pending'   => ['bg-amber-50 text-amber-700 border-amber-200'],
                            'approved'  => ['bg-emerald-50 text-emerald-700 border-emerald-200'],
                            'rejected'  => ['bg-red-50 text-red-700 border-red-200'],
                        ];
                        [$statusStyle] = $statusStyles[$deposit->status] ?? ['bg-slate-50 text-slate-700 border-slate-200'];
                    @endphp
                    <tr class="hover:bg-slate-50/60 transition">
                        <td class="px-6 py-4 font-mono text-xs text-slate-600">#{{ $deposit->id }}</td>
                        <td class="px-6 py-4 text-sm font-semibold text-slate-800">{{ number_format($deposit->amount, 2) }} SAR</td>
                        <td class="px-6 py-4 text-xs text-slate-500">{{ $deposit->payment_method }}</td>
                        <td class="px-6 py-4 text-xs text-slate-500">{{ $deposit->created_at->format('M d, Y g:i A') }}</td>
                        <td class="px-6 py-4">
                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium border {{ $statusStyle }}">{{ ucfirst($deposit->status) }}</span>
                        </td>
                        <td class="px-6 py-4 text-xs text-slate-500">
                            @if ($deposit->receipt_path)
                                <a href="{{ asset('storage/' . $deposit->receipt_path) }}" target="_blank" class="text-brand-600 hover:text-brand-700 font-medium">View</a>
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="6" class="px-6 py-16 text-center">
                            <div class="w-14 h-14 mx-auto rounded-full bg-slate-100 text-slate-400 flex items-center justify-center mb-4">
                                <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h2m-5-7h16a1 1 0 011 1v10a1 1 0 01-1 1H5a2 2 0 01-2-2V7a2 2 0 012-2z"/></svg>
                            </div>
                            <p class="text-sm font-medium text-slate-600">No deposits yet</p>
                            <p class="text-xs text-slate-400 mt-1">Your deposit history will appear here.</p>
                            <a href="{{ route('user.deposits.create') }}" class="inline-block mt-4 px-4 py-2.5 rounded-xl bg-gradient-to-r from-indigo-500 to-fuchsia-500 text-white text-sm font-semibold shadow-lg shadow-indigo-500/25 hover:from-indigo-600 hover:to-fuchsia-600 transition">Make a Deposit</a>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($deposits->hasPages())
        <div class="px-6 py-4 border-t border-slate-100">
            {{ $deposits->links() }}
        </div>
        @endif
    </div>
</div>
@endsection

