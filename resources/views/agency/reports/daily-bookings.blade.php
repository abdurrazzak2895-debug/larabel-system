@extends('layouts.panel')

@section('title', 'Daily Bookings Report')
@section('page-title', 'Daily Bookings Report')

@section('content')
<div class="mb-6">
    <h2 class="text-xl font-bold text-slate-900">Daily Bookings Report</h2>
    <p class="text-sm text-slate-500 mt-0.5">Bookings created per day.</p>
</div>

<div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
        <h3 class="text-sm font-semibold text-slate-700">Daily Summary</h3>
        <span class="text-xs font-medium bg-slate-100 text-slate-600 px-3 py-1.5 rounded-full">{{ count($data) }} days</span>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-left">
                <tr>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Date</th>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Bookings</th>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Failed</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($data as $row)
                    <tr class="hover:bg-slate-50/50 transition">
                        <td class="px-6 py-4 font-medium text-slate-700">{{ $row->date }}</td>
                        <td class="px-6 py-4">
                            <span class="inline-flex px-2.5 py-1 rounded-full text-xs font-medium bg-indigo-50 text-indigo-700 border border-indigo-200">{{ $row->count }}</span>
                        </td>
                        <td class="px-6 py-4">
                            <span class="inline-flex px-2.5 py-1 rounded-full text-xs font-medium {{ ($row->failed ?? 0) > 0 ? 'bg-red-50 text-red-700 border border-red-200' : 'bg-slate-50 text-slate-500 border border-slate-200' }}">{{ $row->failed ?? 0 }}</span>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="px-6 py-12 text-center text-sm text-slate-400">No data available.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
