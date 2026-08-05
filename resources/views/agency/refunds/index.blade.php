@extends('layouts.panel')

@section('title', 'Agency Refunds')
@section('page-title', 'Refund Requests')

@section('content')
<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-xl font-bold text-slate-900">Refund Requests</h2>
        <p class="text-sm text-slate-500 mt-0.5">Request refunds for failed or cancelled exam bookings.</p>
    </div>
    <a href="{{ route('agency.refunds.create') }}"
       class="inline-flex items-center gap-2 px-4 py-2.5 bg-gradient-to-r from-indigo-500 to-fuchsia-500 hover:from-indigo-600 hover:to-fuchsia-600 text-white text-sm font-semibold rounded-xl shadow-lg shadow-indigo-500/20 transition">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
        New Refund
    </a>
</div>

<div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
        <div>
            <h3 class="text-sm font-semibold text-slate-700">Refund History</h3>
            <p class="text-xs text-slate-400 mt-0.5">{{ $refunds->total() }} total</p>
        </div>
        <span class="text-xs font-medium bg-slate-100 text-slate-600 px-3 py-1.5 rounded-full">Refunds</span>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-left">
                <tr>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">ID</th>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Booking</th>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Amount</th>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Reason</th>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Status</th>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Date</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($refunds as $refund)
                    @php
                        $statusColors = [
                            'pending'  => 'bg-amber-50 text-amber-700 border-amber-200',
                            'approved' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                            'rejected' => 'bg-red-50 text-red-700 border-red-200',
                        ];
                        $color = $statusColors[$refund->status] ?? 'bg-slate-50 text-slate-600 border-slate-200';
                    @endphp
                    <tr class="hover:bg-slate-50/50 transition">
                        <td class="px-6 py-4 font-mono text-xs text-slate-500">#{{ $refund->id }}</td>
                        <td class="px-6 py-4">
                            @if ($refund->booking)
                                <a href="{{ route('agency.bookings.show', $refund->booking) }}" class="font-mono text-xs text-brand-600 hover:text-brand-700 font-medium">
                                    {{ $refund->booking->booking_reference }}
                                </a>
                            @else
                                <span class="font-mono text-xs text-slate-500">#{{ $refund->booking_id }}</span>
                            @endif
                        </td>
                        <td class="px-6 py-4 font-bold text-slate-900">{{ number_format($refund->amount, 2) }} <span class="text-xs font-medium text-slate-400">SAR</span></td>
                        <td class="px-6 py-4 text-slate-500 max-w-[220px] truncate">{{ $refund->reason }}</td>
                        <td class="px-6 py-4">
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium border {{ $color }}">
                                <span class="w-1.5 h-1.5 rounded-full {{ $refund->status === 'approved' ? 'bg-emerald-500' : ($refund->status === 'pending' ? 'bg-amber-500' : 'bg-red-500') }}"></span>
                                {{ ucfirst($refund->status) }}
                            </span>
                        </td>
                        <td class="px-6 py-4 text-slate-500">{{ $refund->created_at?->format('d M Y H:i') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-12 text-center">
                            <div class="flex flex-col items-center gap-3">
                                <div class="w-12 h-12 rounded-full bg-slate-100 flex items-center justify-center">
                                    <svg class="w-6 h-6 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                </div>
                                <p class="text-sm text-slate-400">No refund requests yet.</p>
                                <a href="{{ route('agency.refunds.create') }}" class="text-xs font-medium text-brand-600 hover:text-brand-700">Request a refund →</a>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="px-6 py-4 border-t border-slate-100">
        {{ $refunds->links() }}
    </div>
</div>
@endsection
