@extends('layouts.panel')

@section('title', 'Audit Logs')
@section('page-title', 'Audit Logs')

@section('content')
<div class="mb-6">
    <h2 class="text-xl font-bold text-slate-900">Audit Logs</h2>
    <p class="text-sm text-slate-500 mt-0.5">System activity trail.</p>
</div>

{{-- Filters --}}
<div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 mb-6">
    <form method="GET" action="{{ route('admin.audit-logs.index') }}" class="flex flex-col sm:flex-row gap-3">
        <input type="text" name="event" value="{{ request('event') }}" placeholder="Filter by event…"
            class="flex-1 rounded-xl border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
        <input type="text" name="actor_type" value="{{ request('actor_type') }}" placeholder="Filter by actor type…"
            class="flex-1 rounded-xl border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
        <button type="submit"
            class="inline-flex items-center gap-2 px-4 py-2.5 bg-slate-800 hover:bg-slate-900 text-white text-sm font-semibold rounded-xl transition">
            Filter
        </button>
    </form>
</div>

<div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
        <div>
            <h3 class="text-sm font-semibold text-slate-700">Activity Log</h3>
            <p class="text-xs text-slate-400 mt-0.5">{{ $logs->total() }} events recorded</p>
        </div>
        <span class="text-xs font-medium bg-slate-100 text-slate-600 px-3 py-1.5 rounded-full">Audit Trail</span>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-left">
                <tr>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">ID</th>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Actor</th>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Type</th>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Event</th>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Payload</th>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">IP</th>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Created</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($logs as $log)
                    @php
                        $event = strtolower($log->event ?? 'unknown');
                        $badge = match (true) {
                            str_contains($event, 'create') => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                            str_contains($event, 'update') => 'bg-sky-50 text-sky-700 border-sky-200',
                            str_contains($event, 'delete') => 'bg-red-50 text-red-700 border-red-200',
                            default => 'bg-slate-100 text-slate-600 border-slate-200',
                        };
                    @endphp
                    <tr class="hover:bg-slate-50/50 transition">
                        <td class="px-6 py-4 font-mono text-xs text-slate-500">#{{ $log->id }}</td>
                        <td class="px-6 py-4 font-medium text-slate-700">{{ $log->actor_id ?? '—' }}</td>
                        <td class="px-6 py-4 text-xs text-slate-500">{{ class_basename($log->actor_type ?? '—') }}</td>
                        <td class="px-6 py-4">
                            <span class="inline-flex px-2.5 py-1 rounded-full text-xs font-medium border {{ $badge }}">{{ $log->event }}</span>
                        </td>
                        <td class="px-6 py-4 font-mono text-xs text-slate-500 max-w-[200px] truncate">{{ json_encode($log->payload) }}</td>
                        <td class="px-6 py-4 font-mono text-xs text-slate-500">{{ $log->ip_address ?? '—' }}</td>
                        <td class="px-6 py-4 text-slate-500">{{ $log->created_at?->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-6 py-12 text-center">
                            <p class="text-sm text-slate-400">No audit logs found.</p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="px-6 py-4 border-t border-slate-100">
        {{ $logs->links() }}
    </div>
</div>
@endsection
