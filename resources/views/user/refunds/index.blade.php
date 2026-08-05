@extends('layouts.user')

@section('title', 'Refunds')
@section('page-title', 'Refunds')

@section('content')
<div class="space-y-6">
    {{-- ===================== Page header ===================== --}}
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Refunds</h1>
            <p class="text-sm text-slate-500 mt-1">Track and manage your refund requests.</p>
        </div>
        <a href="{{ route('user.refunds.create') }}" class="inline-flex items-center justify-center gap-2 px-4 py-2.5 bg-gradient-to-r from-indigo-500 to-fuchsia-500 hover:from-indigo-600 hover:to-fuchsia-600 text-white text-sm font-semibold rounded-xl shadow-lg shadow-indigo-500/25 transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            New Refund
        </a>
    </div>

    {{-- ===================== Summary cards ===================== --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 flex items-center gap-4">
            <div class="w-11 h-11 shrink-0 rounded-xl bg-sky-50 text-sky-600 flex items-center justify-center">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
            </div>
            <div>
                <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">Total Refunded</p>
                <p class="text-lg font-bold text-slate-900 mt-0.5">{{ number_format($totalRefunded, 2) }} SAR</p>
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

    {{-- ===================== Refunds table ===================== --}}
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100">
            <h3 class="text-sm font-semibold text-slate-800">Refund History</h3>
            <p class="text-xs text-slate-400 mt-0.5">All your refund requests</p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50/70">
                    <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500">
                        <th class="px-6 py-3 font-medium">ID</th>
                        <th class="px-6 py-3 font-medium">Booking</th>
                        <th class="px-6 py-3 font-medium">Date</th>
                        <th class="px-6 py-3 font-medium">Amount</th>
                        <th class="px-6 py-3 font-medium">Reason</th>
                        <th class="px-6 py-3 font-medium">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($refunds as $refund)
                    @php
                        $statusStyles = [
                            'pending'   => ['bg-amber-50 text-amber-700 border-amber-200'],
                            'approved'  => ['bg-emerald-50 text-emerald-700 border-emerald-200'],
                            'rejected'  => ['bg-red-50 text-red-700 border-red-200'],
                            'processed' => ['bg-sky-50 text-sky-700 border-sky-200'],
                        ];
                        [$statusStyle] = $statusStyles[$refund->status] ?? ['bg-slate-50 text-slate-700 border-slate-200'];
                    @endphp
                    <tr class="hover:bg-slate-50/60 transition">
                        <td class="px-6 py-4 font-mono text-xs text-slate-600">#{{ $refund->id }}</td>
                        <td class="px-6 py-4 text-xs text-slate-500">
                            @if ($refund->booking)
                            <a href="{{ route('user.bookings.show', $refund->booking) }}" class="font-medium text-brand-600 hover:text-brand-700 hover:underline">
                                #{{ $refund->booking->id }} · {{ $refund->booking->booking_reference ?? 'N/A' }}
                            </a>
                            @else
                            {{ $refund->booking_id ?? '—' }}
                            @endif
                        </td>
                        <td class="px-6 py-4 text-xs text-slate-500">{{ $refund->created_at->format('M d, Y g:i A') }}</td>
                        <td class="px-6 py-4 text-sm font-semibold text-slate-800">{{ number_format($refund->amount ?? 0, 2) }} SAR</td>
                        <td class="px-6 py-4 text-xs text-slate-500 max-w-xs truncate">{{ $refund->reason }}</td>
                        <td class="px-6 py-4">
                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium border {{ $statusStyle }}">{{ ucfirst($refund->status) }}</span>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="6" class="px-6 py-16 text-center">
                            <div class="w-14 h-14 mx-auto rounded-full bg-slate-100 text-slate-400 flex items-center justify-center mb-4">
                                <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
                            </div>
                            <p class="text-sm font-medium text-slate-600">No refunds yet</p>
                            <p class="text-xs text-slate-400 mt-1">Refund requests will appear here.</p>
                            <a href="{{ route('user.refunds.create') }}" class="inline-block mt-4 px-4 py-2.5 rounded-xl bg-gradient-to-r from-indigo-500 to-fuchsia-500 text-white text-sm font-semibold shadow-lg shadow-indigo-500/25 hover:from-indigo-600 hover:to-fuchsia-600 transition">Request Refund</a>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($refunds->hasPages())
        <div class="px-6 py-4 border-t border-slate-100">
            {{ $refunds->links() }}
        </div>
        @endif
    </div>
</div>
@endsection

