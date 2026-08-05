@extends('layouts.user')

@section('title', 'Notifications')
@section('page-title', 'Notifications')

@section('content')
<div class="space-y-6">

    {{-- ===================== Page header ===================== --}}
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Notifications</h1>
            <p class="text-sm text-slate-500 mt-1">{{ $unreadCount > 0 ? 'You have ' . $unreadCount . ' unread notification' . ($unreadCount > 1 ? 's' : '') . '.' : 'You\'re all caught up.' }}</p>
        </div>
        @if ($unreadCount > 0)
        <form method="POST" action="{{ route('user.notifications.mark-all-read') }}">
            @csrf
            <button type="submit" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl border border-slate-200 bg-white text-slate-700 text-sm font-medium hover:bg-slate-50 hover:border-slate-300 transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                Mark all as read
            </button>
        </form>
        @endif
    </div>

    {{-- ===================== Notifications list ===================== --}}
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="divide-y divide-slate-50">
            @forelse ($notifications as $notification)
            <div class="px-6 py-5 flex items-start gap-4 {{ $notification->read_at ? '' : 'bg-indigo-50/40' }} hover:bg-slate-50/60 transition">
                <span class="w-10 h-10 shrink-0 rounded-xl flex items-center justify-center {{ $notification->read_at ? 'bg-slate-100 text-slate-400' : 'bg-indigo-100 text-indigo-600' }}">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                </span>
                <div class="flex-1 min-w-0">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-sm font-semibold {{ $notification->read_at ? 'text-slate-700' : 'text-slate-900' }}">{{ $notification->title }}</p>
                            <p class="text-xs text-slate-500 mt-1 leading-relaxed">{{ $notification->body }}</p>
                            <p class="text-[11px] text-slate-400 mt-2">{{ $notification->created_at->format('M d, Y g:i A') }}</p>
                        </div>
                        <div class="flex items-center gap-3 shrink-0">
                            @unless ($notification->read_at)
                            <span class="w-2.5 h-2.5 rounded-full bg-indigo-500"></span>
                            <form method="POST" action="{{ route('user.notifications.mark-read', $notification) }}">
                                @csrf
                                <button type="submit" class="text-xs font-medium text-brand-600 hover:text-brand-700 transition">Mark read</button>
                            </form>
                            @else
                            <span class="text-[11px] text-slate-400">Read</span>
                            @endunless
                        </div>
                    </div>
                </div>
            </div>
            @empty
            <div class="px-6 py-16 text-center">
                <div class="w-14 h-14 mx-auto rounded-full bg-slate-100 text-slate-400 flex items-center justify-center mb-4">
                    <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                </div>
                <p class="text-sm font-medium text-slate-600">No notifications</p>
                <p class="text-xs text-slate-400 mt-1">We'll notify you here when something happens with your bookings.</p>
            </div>
            @endforelse
        </div>

        @if ($notifications->hasPages())
        <div class="px-6 py-4 border-t border-slate-100">
            {{ $notifications->links() }}
        </div>
        @endif
    </div>
</div>
@endsection
