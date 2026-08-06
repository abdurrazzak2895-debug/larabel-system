@extends('layouts.panel')

@section('title', 'Test Centers')
@section('page-title', 'Test Centers')

@section('content')
<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-xl font-bold text-slate-900">Test Centers</h2>
        <p class="text-sm text-slate-500 mt-0.5">Live test centers synced from the real SVP / Takamol API.</p>
    </div>
    <form action="{{ route('admin.test-centers.sync') }}" method="POST"
          onsubmit="this.querySelector('button').disabled = true; this.querySelector('button span').textContent = 'Syncing…';">
        @csrf
        <button type="submit"
            class="inline-flex items-center gap-2 px-4 py-2.5 bg-gradient-to-r from-indigo-500 to-fuchsia-500 hover:from-indigo-600 hover:to-fuchsia-600 text-white text-sm font-semibold rounded-xl shadow-lg shadow-indigo-500/20 transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
            <span>Sync from SVP API</span>
        </button>
    </form>
</div>

{{-- Filters --}}
<div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 mb-6">
    <form method="GET" action="{{ route('admin.test-centers.index') }}" class="flex flex-wrap items-center gap-3">
        <div class="min-w-48 flex-1">
            <input type="text" name="q" value="{{ $search }}" placeholder="Search name or SVP ID…"
                class="w-full rounded-xl border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
        </div>
        <div class="min-w-40">
            <select name="city" class="w-full rounded-xl border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
                <option value="">All cities</option>
                @foreach ($cities as $city)
                    <option value="{{ $city }}" {{ $filter === $city ? 'selected' : '' }}>{{ $city }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit"
            class="px-4 py-2.5 bg-slate-900 hover:bg-slate-800 text-white text-sm font-semibold rounded-xl transition">
            Filter
        </button>
        @if ($filter || $search)
            <a href="{{ route('admin.test-centers.index') }}" class="text-sm font-medium text-slate-500 hover:text-slate-700 transition">Reset</a>
        @endif
    </form>
</div>

<div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
        <div>
            <h3 class="text-sm font-semibold text-slate-700">All Test Centers</h3>
            <p class="text-xs text-slate-400 mt-0.5">{{ $testCenters->total() }} total</p>
        </div>
        <span class="text-xs font-medium bg-slate-100 text-slate-600 px-3 py-1.5 rounded-full">SVP / Takamol</span>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-left">
                <tr>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">SVP ID</th>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Test Center Name</th>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">City</th>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Country</th>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Synced At</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($testCenters as $center)
                    <tr class="hover:bg-slate-50/50 transition">
                        <td class="px-6 py-4 font-mono text-xs text-slate-500">#{{ $center->svp_id }}</td>
                        <td class="px-6 py-4 font-medium text-slate-700">{{ $center->name }}</td>
                        <td class="px-6 py-4">
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-brand-50 text-brand-700 border border-brand-100">
                                {{ $center->city }}
                            </span>
                        </td>
                        <td class="px-6 py-4 text-slate-500">{{ $center->country_code ?? '—' }}</td>
                        <td class="px-6 py-4 text-slate-400 text-xs">
                            {{ $center->updated_at ? $center->updated_at->diffForHumans() : '—' }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-6 py-12 text-center">
                            <p class="text-sm text-slate-400">No test centers found.</p>
                            <p class="text-xs text-slate-400 mt-1">Run <code class="font-mono">php artisan db:seed --class=TestCenterSeeder</code> or use “Sync from SVP API” to populate.</p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($testCenters->hasPages())
        <div class="px-6 py-4 border-t border-slate-100">
            {{ $testCenters->links() }}
        </div>
    @endif
</div>
@endsection
