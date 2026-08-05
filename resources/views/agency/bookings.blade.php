@extends('layouts.panel')

@section('title', 'My Bookings')

@section('content')
<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-xl font-bold text-slate-900">My Bookings</h2>
        <p class="text-sm text-slate-500 mt-0.5">Local bookings created through the portal and live reservations from SVP.</p>
    </div>
    <a href="{{ route('agency.bookings.create') }}" class="inline-flex items-center gap-2 px-4 py-2 bg-brand-600 hover:bg-brand-700 text-white text-sm font-medium rounded-lg transition">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
        New Booking
    </a>
</div>

@if ($svpError)
    <div class="mb-4 px-4 py-3 bg-amber-50 border border-amber-200 text-amber-700 rounded-xl text-sm">
        {{ $svpError }}
        @if (! $hasSvpToken)
            <a href="{{ route('svp.login.form') }}" class="ml-2 underline font-semibold">Sign in with SVP</a>
        @endif
    </div>
@endif

{{-- Local bookings --}}
<div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden mb-8">
    <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
        <h3 class="text-sm font-semibold text-slate-700">Portal Bookings</h3>
        <span class="text-xs text-slate-400">{{ $localBookings->total() }} total</span>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-slate-500">
                <tr>
                    <th class="text-left px-6 py-3 font-medium">Reference</th>
                    <th class="text-left px-6 py-3 font-medium">Candidate</th>
                    <th class="text-left px-6 py-3 font-medium">Session</th>
                    <th class="text-left px-6 py-3 font-medium">Status</th>
                    <th class="text-left px-6 py-3 font-medium">Created</th>
                    <th class="text-right px-6 py-3 font-medium">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($localBookings as $b)
                    @php
                        $statusColors = [
                            'pending'     => 'bg-amber-50 text-amber-700 border-amber-200',
                            'processing'  => 'bg-blue-50 text-blue-700 border-blue-200',
                            'booked'      => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                            'failed'      => 'bg-red-50 text-red-700 border-red-200',
                            'cancelled'   => 'bg-slate-100 text-slate-600 border-slate-200',
                            'refunded'    => 'bg-purple-50 text-purple-700 border-purple-200',
                        ];
                        $color = $statusColors[$b->booking_status] ?? 'bg-slate-50 text-slate-700 border-slate-200';
                    @endphp
                    <tr class="hover:bg-slate-50/50 transition">
                        <td class="px-6 py-4 font-mono text-xs text-slate-600">{{ $b->booking_reference }}</td>
                        <td class="px-6 py-4 text-slate-700">{{ $b->credential?->full_name ?? '—' }}</td>
                        <td class="px-6 py-4 text-slate-600 text-xs">{{ $b->exam_session_id ?? '—' }}</td>
                        <td class="px-6 py-4">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium border {{ $color }}">
                                {{ $b->booking_status }}
                            </span>
                        </td>
                        <td class="px-6 py-4 text-slate-500 text-xs">{{ $b->created_at?->diffForHumans() }}</td>
                        <td class="px-6 py-4 text-right">
                            <a href="{{ route('agency.bookings.show', $b) }}" class="text-brand-600 hover:text-brand-700 text-xs font-medium">View</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-10 text-center text-slate-400 text-sm">No bookings yet. Click "New Booking" to get started.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="px-6 py-3 border-t border-slate-100">
        {{ $localBookings->links() }}
    </div>
</div>

{{-- SVP live reservations --}}
@if ($svpReservations)
    @php
        $items = is_array($svpReservations) ? $svpReservations : ($svpReservations->data ?? []);
        if (! is_array($items)) { $items = []; }
    @endphp
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-slate-700">SVP Live Reservations</h3>
            <span class="text-xs text-slate-400">{{ count($items) }} found</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-slate-500">
                    <tr>
                        <th class="text-left px-6 py-3 font-medium">ID</th>
                        <th class="text-left px-6 py-3 font-medium">Session</th>
                        <th class="text-left px-6 py-3 font-medium">Date</th>
                        <th class="text-left px-6 py-3 font-medium">Status</th>
                        <th class="text-right px-6 py-3 font-medium">Raw</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($items as $r)
                        @php $r = (array) $r; @endphp
                        <tr class="hover:bg-slate-50/50 transition">
                            <td class="px-6 py-4 font-mono text-xs text-slate-600">{{ $r['id'] ?? '—' }}</td>
                            <td class="px-6 py-4 text-slate-700">{{ $r['exam_session_id'] ?? $r['session_id'] ?? '—' }}</td>
                            <td class="px-6 py-4 text-slate-600 text-xs">{{ $r['exam_date'] ?? $r['date'] ?? '—' }}</td>
                            <td class="px-6 py-4 text-slate-600 text-xs">{{ $r['status'] ?? '—' }}</td>
                            <td class="px-6 py-4 text-right">
                                <details class="text-left">
                                    <summary class="text-brand-600 text-xs cursor-pointer">JSON</summary>
                                    <pre class="mt-2 text-[10px] bg-slate-50 p-2 rounded overflow-auto max-h-40">{{ json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                </details>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-6 py-8 text-center text-slate-400 text-sm">No live reservations returned by SVP.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endif
@endsection
