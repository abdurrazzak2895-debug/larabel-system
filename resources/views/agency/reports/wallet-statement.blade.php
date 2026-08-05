@extends('layouts.panel')

@section('title', 'Wallet Statement')
@section('page-title', 'Wallet Statement')

@section('content')
<div class="mb-6">
    <h2 class="text-xl font-bold text-slate-900">Wallet Statement</h2>
    <p class="text-sm text-slate-500 mt-0.5">Complete financial movements on your agency wallet.</p>
</div>

<div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
        <h3 class="text-sm font-semibold text-slate-700">Statement</h3>
        <span class="text-xs font-medium bg-slate-100 text-slate-600 px-3 py-1.5 rounded-full">{{ count($data) }} entries</span>
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
                @forelse ($data as $row)
                    @php
                        $credit = in_array(strtolower((string) $row->type), ['credit', 'deposit', 'refund', 'adjustment'], true);
                    @endphp
                    <tr class="hover:bg-slate-50/50 transition">
                        <td class="px-6 py-4 font-mono text-xs text-slate-500">#{{ $row->id }}</td>
                        <td class="px-6 py-4">
                            <span class="inline-flex px-2 py-0.5 rounded text-xs font-medium border {{ $credit ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-rose-50 text-rose-700 border-rose-200' }}">
                                {{ $row->type }}
                            </span>
                        </td>
                        <td class="px-6 py-4 font-semibold {{ $credit ? 'text-emerald-600' : 'text-rose-600' }}">
                            {{ $credit ? '+' : '-' }} {{ number_format(abs((float) $row->amount), 2) }}
                        </td>
                        <td class="px-6 py-4 font-mono text-xs text-slate-500">{{ $row->reference ?? '—' }}</td>
                        <td class="px-6 py-4 text-slate-500">{{ $row->created_at }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-6 py-12 text-center text-sm text-slate-400">No statement entries yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
