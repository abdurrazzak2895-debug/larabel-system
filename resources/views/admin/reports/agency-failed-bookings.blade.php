@extends('layouts.panel')

@section('title', 'Agency Failed Bookings')
@section('page-title', 'Agency Failed Bookings')

@section('content')
<div class="flex items-center gap-3 mb-6">
    <a href="{{ route('admin.reports.index') }}" class="text-slate-400 hover:text-slate-600 transition">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
    </a>
    <div>
        <h2 class="text-xl font-bold text-slate-900">Agency Failed Bookings</h2>
        <p class="text-sm text-slate-500 mt-0.5">Failed bookings for the selected agency.</p>
    </div>
</div>

<div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
        <h3 class="text-sm font-semibold text-slate-700">Failed Records</h3>
        <span class="text-xs font-medium bg-slate-100 text-slate-600 px-3 py-1.5 rounded-full">{{ count($data) }} records</span>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-left">
                <tr>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">ID</th>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">User</th>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Status</th>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Created At</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($data as $row)
                    <tr class="hover:bg-slate-50/50 transition">
                        <td class="px-6 py-4 font-mono text-xs text-slate-500">#{{ $row->id }}</td>
                        <td class="px-6 py-4 font-medium text-slate-700">{{ $row->user->name ?? 'N/A' }}</td>
                        <td class="px-6 py-4">
                            <span class="inline-flex px-2.5 py-1 rounded-full text-xs font-medium bg-red-50 text-red-700 border border-red-200">{{ ucfirst($row->status) }}</span>
                        </td>
                        <td class="px-6 py-4 text-slate-500">{{ $row->created_at }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-6 py-12 text-center text-sm text-slate-400">No failed bookings. 🎉</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
