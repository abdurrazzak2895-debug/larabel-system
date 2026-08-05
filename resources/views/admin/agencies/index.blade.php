@extends('layouts.panel')

@section('title', 'Agencies')
@section('page-title', 'Agencies')

@section('content')
<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-xl font-bold text-slate-900">Agencies</h2>
        <p class="text-sm text-slate-500 mt-0.5">Manage partner agencies and their wallet status.</p>
    </div>
    <a href="{{ route('admin.agencies.create') }}"
       class="inline-flex items-center gap-2 px-4 py-2.5 bg-gradient-to-r from-indigo-500 to-fuchsia-500 hover:from-indigo-600 hover:to-fuchsia-600 text-white text-sm font-semibold rounded-xl shadow-lg shadow-indigo-500/20 transition">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
        Create Agency
    </a>
</div>

<div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
        <div>
            <h3 class="text-sm font-semibold text-slate-700">All Agencies</h3>
            <p class="text-xs text-slate-400 mt-0.5">{{ $agencies->total() }} total</p>
        </div>
        <span class="text-xs font-medium bg-slate-100 text-slate-600 px-3 py-1.5 rounded-full">Agencies</span>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-left">
                <tr>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">ID</th>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Name</th>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Code</th>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Wallet Balance</th>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Status</th>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($agencies as $agency)
                    <tr class="hover:bg-slate-50/50 transition">
                        <td class="px-6 py-4 font-mono text-xs text-slate-500">#{{ $agency->id }}</td>
                        <td class="px-6 py-4 font-medium text-slate-700">{{ $agency->name }}</td>
                        <td class="px-6 py-4 font-mono text-xs text-slate-500">{{ $agency->code ?? '—' }}</td>
                        <td class="px-6 py-4 font-bold text-slate-900">{{ number_format($agency->wallet?->available_balance ?? 0, 2) }} <span class="text-xs font-medium text-slate-400">SAR</span></td>
                        <td class="px-6 py-4">
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium border {{ $agency->status ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-red-50 text-red-700 border-red-200' }}">
                                <span class="w-1.5 h-1.5 rounded-full {{ $agency->status ? 'bg-emerald-500' : 'bg-red-500' }}"></span>
                                {{ $agency->status ? 'Active' : 'Suspended' }}
                            </span>
                        </td>
                        <td class="px-6 py-4">
                            <div class="flex items-center gap-3">
                                <a href="{{ route('admin.agencies.edit', $agency) }}" class="text-xs font-medium text-brand-600 hover:text-brand-700 transition">Edit</a>
                                @if ($agency->status)
                                    <form action="{{ route('admin.agencies.suspend', $agency) }}" method="POST"
                                          onsubmit="return confirm('Suspend agency?')">
                                        @csrf
                                        <button type="submit" class="text-xs font-medium text-amber-600 hover:text-amber-700 transition">Suspend</button>
                                    </form>
                                @else
                                    <form action="{{ route('admin.agencies.activate', $agency) }}" method="POST">
                                        @csrf
                                        <button type="submit" class="text-xs font-medium text-emerald-600 hover:text-emerald-700 transition">Activate</button>
                                    </form>
                                @endif
                                <form action="{{ route('admin.agencies.destroy', $agency) }}" method="POST"
                                      onsubmit="return confirm('Delete this agency permanently?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-xs font-medium text-red-600 hover:text-red-700 transition">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-12 text-center">
                            <p class="text-sm text-slate-400">No agencies yet.</p>
                            <a href="{{ route('admin.agencies.create') }}" class="text-xs font-medium text-brand-600 hover:text-brand-700">Create the first agency →</a>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="px-6 py-4 border-t border-slate-100">
        {{ $agencies->links() }}
    </div>
</div>
@endsection
