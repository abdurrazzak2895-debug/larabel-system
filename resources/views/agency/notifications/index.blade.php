@extends('layouts.panel')

@section('title', 'Notifications')
@section('page-title', 'Notifications')

@section('content')
<div class="max-w-4xl">
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
            <div>
                <h3 class="text-sm font-semibold text-slate-700">Inbox</h3>
                <p class="text-xs text-slate-400 mt-0.5">{{ $notifications->total() }} notifications</p>
            </div>
            <span class="text-xs font-medium bg-slate-100 text-slate-600 px-3 py-1.5 rounded-full">Notifications</span>
        </div>
        <div class="divide-y divide-slate-100">
            @forelse ($notifications as $notification)
                <div class="px-6 py-4 flex items-start gap-4 {{ $notification->read_at ? 'opacity-60' : 'bg-indigo-50/40' }}">
                    <div class="w-10 h-10 rounded-full flex items-center justify-center shrink-0 {{ $notification->read_at ? 'bg-slate-100 text-slate-400' : 'bg-brand-100 text-brand-600' }}">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center justify-between gap-3">
                            <p class="text-sm font-semibold text-slate-800">{{ $notification->title }}</p>
                            <span class="text-xs text-slate-400 shrink-0">{{ $notification->created_at?->diffForHumans() }}</span>
                        </div>
                        <p class="text-sm text-slate-500 mt-1">{{ $notification->body }}</p>
                        @if (! $notification->read_at)
                            <form action="{{ route('agency.notifications.mark-read', $notification) }}" method="POST" class="mt-2">
                                @csrf
                                <button type="submit"
                                    class="inline-flex items-center gap-1.5 text-xs font-medium text-brand-600 hover:text-brand-700 transition">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                    Mark as read
                                </button>
                            </form>
                        @endif
                    </div>
                </div>
            @empty
                <div class="px-6 py-16 text-center">
                    <div class="flex flex-col items-center gap-3">
                        <div class="w-14 h-14 rounded-full bg-slate-100 flex items-center justify-center">
                            <svg class="w-7 h-7 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                        </div>
                        <p class="text-sm text-slate-400">You're all caught up — no notifications yet.</p>
                    </div>
                </div>
            @endforelse
        </div>
        <div class="px-6 py-4 border-t border-slate-100">
            {{ $notifications->links() }}
        </div>
    </div>
</div>
@endsection
