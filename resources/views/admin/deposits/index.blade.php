@extends('layouts.panel')

@section('title', 'Deposits')
@section('page-title', 'Deposit Requests')

@section('content')
<div class="mb-6">
    <h2 class="text-xl font-bold text-slate-900">Deposit Requests</h2>
    <p class="text-sm text-slate-500 mt-0.5">Review and approve agency wallet top-ups.</p>
</div>

<div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
        <div>
            <h3 class="text-sm font-semibold text-slate-700">All Deposits</h3>
            <p class="text-xs text-slate-400 mt-0.5">{{ $deposits->total() }} total</p>
        </div>
        <span class="text-xs font-medium bg-slate-100 text-slate-600 px-3 py-1.5 rounded-full">Deposits</span>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-left">
                <tr>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">ID</th>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Agency</th>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Amount</th>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Method</th>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Receipt</th>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Status</th>
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
                        <td class="px-6 py-4 font-medium text-slate-700">{{ $deposit->agency?->name ?? 'N/A' }}</td>
                        <td class="px-6 py-4 font-bold text-slate-900">{{ number_format($deposit->amount, 2) }} <span class="text-xs font-medium text-slate-400">BDT</span></td>
                        <td class="px-6 py-4 text-slate-600">{{ $deposit->payment_method }}</td>
                        <td class="px-6 py-4">
                            @if ($deposit->receipt_path)
                                <a href="{{ asset('storage/' . $deposit->receipt_path) }}" target="_blank" class="text-xs font-medium text-brand-600 hover:text-brand-700">View Receipt</a>
                            @else
                                <span class="text-xs text-slate-400">—</span>
                            @endif
                        </td>
                        <td class="px-6 py-4">
                            <span class="inline-flex px-2.5 py-1 rounded-full text-xs font-medium border {{ $color }}">{{ ucfirst($deposit->status) }}</span>
                        </td>
                        <td class="px-6 py-4">
                            @if ($deposit->status === 'pending')
                                <div class="flex items-center gap-2">
                                    <form action="{{ route('admin.deposits.approve', $deposit) }}" method="POST">
                                        @csrf
                                        <button type="submit" class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold rounded-lg transition">Approve</button>
                                    </form>
                                    <form action="{{ route('admin.deposits.reject', $deposit) }}" method="POST"
                                          onsubmit="return confirm('Reject deposit #{{ $deposit->id }}?')">
                                        @csrf
                                        <input type="hidden" name="reason" value="Rejected by admin">
                                        <button type="submit" class="px-3 py-1.5 bg-red-600 hover:bg-red-700 text-white text-xs font-semibold rounded-lg transition">Reject</button>
                                    </form>
                                </div>
                            @else
                                <span class="text-xs text-slate-400">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-6 py-12 text-center">
                            <p class="text-sm text-slate-400">No deposit requests yet.</p>
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
