@extends('layouts.panel')

@section('title', 'Users')
@section('page-title', 'Users')

@section('content')
<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-xl font-bold text-slate-900">Users</h2>
        <p class="text-sm text-slate-500 mt-0.5">All users across all agencies.</p>
    </div>
    <a href="{{ route('admin.users.create') }}"
       class="inline-flex items-center gap-2 px-4 py-2.5 bg-gradient-to-r from-indigo-500 to-fuchsia-500 hover:from-indigo-600 hover:to-fuchsia-600 text-white text-sm font-semibold rounded-xl shadow-lg shadow-indigo-500/20 transition">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/></svg>
        Create User
    </a>
</div>

<div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
        <div>
            <h3 class="text-sm font-semibold text-slate-700">All Users</h3>
            <p class="text-xs text-slate-400 mt-0.5">{{ $users->total() }} total</p>
        </div>
        <span class="text-xs font-medium bg-slate-100 text-slate-600 px-3 py-1.5 rounded-full">Platform Users</span>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-left">
                <tr>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">ID</th>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Name</th>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Email</th>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Agency</th>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Status</th>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($users as $user)
                    <tr class="hover:bg-slate-50/50 transition">
                        <td class="px-6 py-4 font-mono text-xs text-slate-500">#{{ $user->id }}</td>
                        <td class="px-6 py-4 font-medium text-slate-700">{{ $user->name }}</td>
                        <td class="px-6 py-4 text-slate-500">{{ $user->email }}</td>
                        <td class="px-6 py-4 text-slate-600">{{ $user->agency?->name ?? '—' }}</td>
                        <td class="px-6 py-4">
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium border {{ $user->status ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-slate-100 text-slate-600 border-slate-200' }}">
                                <span class="w-1.5 h-1.5 rounded-full {{ $user->status ? 'bg-emerald-500' : 'bg-slate-400' }}"></span>
                                {{ $user->status ? 'Active' : 'Disabled' }}
                            </span>
                        </td>
                        <td class="px-6 py-4">
                            <div class="flex items-center gap-3">
                                <a href="{{ route('admin.users.edit', $user) }}" class="text-xs font-medium text-brand-600 hover:text-brand-700 transition">Edit</a>
                                @if ($user->status)
                                    <form action="{{ route('admin.users.disable', $user) }}" method="POST"
                                          onsubmit="return confirm('Disable this user? They will not be able to log in.')">
                                        @csrf
                                        <button type="submit" class="text-xs font-medium text-red-600 hover:text-red-700 transition">Disable</button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-12 text-center">
                            <p class="text-sm text-slate-400">No users yet.</p>
                            <a href="{{ route('admin.users.create') }}" class="text-xs font-medium text-brand-600 hover:text-brand-700">Create the first user →</a>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="px-6 py-4 border-t border-slate-100">
        {{ $users->links() }}
    </div>
</div>
@endsection
