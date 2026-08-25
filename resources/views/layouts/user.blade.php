<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Dashboard') · SVP Takamol</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: {
                            50: '#eef2ff', 100: '#e0e7ff', 200: '#c7d2fe', 300: '#a5b4fc',
                            400: '#818cf8', 500: '#6366f1', 600: '#4f46e5', 700: '#4338ca',
                            800: '#3730a3', 900: '#312e81',
                        }
                    },
                    fontFamily: {
                        sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
                    }
                }
            }
        }
    </script>
    <style>
        body { font-family: 'Inter', ui-sans-serif, system-ui, sans-serif; }
        .sidebar-scroll::-webkit-scrollbar { width: 5px; }
        .sidebar-scroll::-webkit-scrollbar-thumb { background: rgba(129, 140, 248, 0.35); border-radius: 9999px; }
        .sidebar-scroll::-webkit-scrollbar-track { background: transparent; }
    </style>
</head>
<body class="bg-slate-100 text-slate-800 min-h-screen antialiased">

@php
    $__user = Auth::user();
    $__routeName = request()->route() ? request()->route()->getName() : '';
    $__unread = $__user ? \App\Models\Notification::where('user_id', $__user->getAuthIdentifier())->whereNull('read_at')->count() : 0;

    $__icons = [
        'home' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>',
        'calendar' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>',
        'wallet' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h2m-5-7h16a1 1 0 011 1v10a1 1 0 01-1 1H5a2 2 0 01-2-2V7a2 2 0 012-2z"/></svg>',
        'banknotes' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
        'rotate' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>',
        'bell' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>',
        'plus' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>',
    ];
@endphp
@php
    $__nav = [
        ['label' => 'Dashboard',       'route' => 'user.dashboard',           'match' => 'user.dashboard',        'icon' => 'home'],
        ['label' => 'My Bookings',     'route' => 'user.bookings.index',      'match' => 'user.bookings',         'icon' => 'calendar'],
        ['label' => 'Wallet',          'route' => 'user.wallets.index',       'match' => 'user.wallets',          'icon' => 'wallet'],
        ['label' => 'Deposits',        'route' => 'user.deposits.index',      'match' => 'user.deposits',         'icon' => 'banknotes'],
        ['label' => 'Refunds',         'route' => 'user.refunds.index',       'match' => 'user.refunds',          'icon' => 'rotate'],
        ['label' => 'Notifications',   'route' => 'user.notifications.index', 'match' => 'user.notifications',   'icon' => 'bell'],
    ];

    $__navLink = function (array $item) use ($__icons, $__routeName, $__unread) {
        $active = str_starts_with((string) $__routeName, $item['match']);
        $href = route($item['route']);
        $classes = $active
            ? 'flex items-center gap-3 px-3 py-2.5 rounded-xl text-white bg-gradient-to-r from-brand-600 to-indigo-500 shadow-lg shadow-brand-900/30 font-semibold'
            : 'flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-400 hover:text-white hover:bg-white/5 font-medium transition';
        $badge = ($item['route'] === 'user.notifications.index' && $__unread > 0)
            ? '<span class="ml-auto text-[10px] font-bold bg-red-500 text-white rounded-full px-2 py-0.5">' . ($__unread > 9 ? '9+' : $__unread) . '</span>'
            : '';
        return '<a href="' . e($href) . '" class="' . $classes . '">' . ($__icons[$item['icon']] ?? '') . '<span class="text-sm">' . e($item['label']) . '</span>' . $badge . '</a>';
    };
@endphp

<!-- ===================== SIDEBAR ===================== -->
<aside id="user-sidebar" class="fixed inset-y-0 left-0 z-50 w-64 bg-gradient-to-b from-slate-900 via-slate-900 to-indigo-950 border-r border-slate-800 transform -translate-x-full lg:translate-x-0 transition-transform duration-300">
    <div class="flex flex-col h-full">
        {{-- Brand --}}
        <div class="px-5 h-16 flex items-center gap-3 border-b border-white/5 shrink-0">
            <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-indigo-400 to-fuchsia-500 flex items-center justify-center text-white text-sm font-black shadow-lg shadow-indigo-500/30">S</div>
            <div class="min-w-0">
                <p class="text-white font-bold text-sm leading-tight">SVP Takamol</p>
                <p class="text-[11px] text-indigo-300/80">User Portal</p>
            </div>
        </div>

        {{-- Navigation --}}
        <nav class="flex-1 overflow-y-auto sidebar-scroll px-3 py-5 space-y-1">
            @foreach ($__nav as $item)
                {!! $__navLink($item) !!}
            @endforeach
        </nav>


        {{-- User card --}}
        <div class="p-3 border-t border-white/5 shrink-0">
            <div class="flex items-center gap-3 px-2 py-2 rounded-xl bg-white/5">
                <div class="w-9 h-9 rounded-full bg-gradient-to-br from-indigo-400 to-fuchsia-500 flex items-center justify-center text-white text-sm font-bold shrink-0">
                    {{ strtoupper(substr($__user?->name ?? 'U', 0, 1)) }}
                </div>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-white truncate">{{ $__user?->name ?? 'Guest' }}</p>
                    <p class="text-[11px] text-slate-500 truncate">Signed in as user</p>
                </div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" title="Logout" class="text-slate-500 hover:text-red-400 transition p-1.5 rounded-lg hover:bg-red-500/10">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                    </button>
                </form>
            </div>
        </div>
    </div>
</aside>

<!-- ===================== MAIN ===================== -->
<div class="lg:pl-64 min-h-screen flex flex-col">
    {{-- Header --}}
    <header class="sticky top-0 z-30 bg-white/85 backdrop-blur-xl border-b border-slate-200 h-16 flex items-center gap-3 sm:gap-4 px-4 sm:px-6 lg:px-8">
        <button onclick="openUserSidebar()" class="lg:hidden p-2 rounded-lg text-slate-500 hover:bg-slate-100 transition" aria-label="Open menu">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>

        <div class="min-w-0 flex-1">
            <h1 class="text-base sm:text-lg font-bold text-slate-900 truncate">{{ trim(View::getSection('page-title')) !== '' ? trim(View::getSection('page-title')) : (trim(View::getSection('title')) !== '' ? trim(View::getSection('title')) : 'Dashboard') }}</h1>
        </div>

        <a href="{{ route('user.notifications.index') }}" class="relative p-2.5 rounded-xl text-slate-500 hover:bg-slate-100 hover:text-slate-700 transition" title="Notifications">
            {!! $__icons['bell'] !!}
            @if ($__unread > 0)
                <span class="absolute top-1.5 right-1.5 min-w-[18px] h-[18px] px-1 rounded-full bg-red-500 text-white text-[10px] font-bold flex items-center justify-center">{{ $__unread > 9 ? '9+' : $__unread }}</span>
            @endif
        </a>

        <div class="hidden sm:flex items-center gap-2.5 pl-3 border-l border-slate-200">
            <div class="w-9 h-9 rounded-full bg-gradient-to-br from-indigo-400 to-fuchsia-500 flex items-center justify-center text-white text-sm font-bold">
                {{ strtoupper(substr($__user?->name ?? 'U', 0, 1)) }}
            </div>
            <div class="min-w-0 hidden md:block">
                <p class="text-sm font-semibold text-slate-800 leading-tight truncate max-w-[140px]">{{ $__user?->name ?? 'Guest' }}</p>
                <p class="text-[11px] text-slate-400 leading-tight">{{ $__user?->email ?? '' }}</p>
            </div>
        </div>
    </header>

    {{-- Content --}}
    <main class="flex-1 px-4 sm:px-6 lg:px-8 py-6 lg:py-8 max-w-7xl w-full mx-auto">
        @if (session('success'))
        <div class="mb-5 flex items-start gap-3 px-4 py-3 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-xl text-sm" id="flash-success">
            <svg class="w-5 h-5 shrink-0 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span class="flex-1">{{ session('success') }}</span>
            <button onclick="this.closest('div').remove()" class="text-emerald-500 hover:text-emerald-700">&times;</button>
        </div>
        @endif
        @if (session('error'))
        <div class="mb-5 flex items-start gap-3 px-4 py-3 bg-red-50 border border-red-200 text-red-800 rounded-xl text-sm" id="flash-error">
            <svg class="w-5 h-5 shrink-0 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span class="flex-1">{{ session('error') }}</span>
            <button onclick="this.closest('div').remove()" class="text-red-500 hover:text-red-700">&times;</button>
        </div>
        @endif
        @if (session('status'))
        <div class="mb-5 flex items-start gap-3 px-4 py-3 bg-blue-50 border border-blue-200 text-blue-800 rounded-xl text-sm" id="flash-status">
            <svg class="w-5 h-5 shrink-0 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span class="flex-1">{{ session('status') }}</span>
            <button onclick="this.closest('div').remove()" class="text-blue-500 hover:text-blue-700">&times;</button>
        </div>
        @endif

        @yield('content')
    </main>

    {{-- Footer --}}
    <footer class="px-4 sm:px-6 lg:px-8 py-5 border-t border-slate-200 text-center">
        <p class="text-xs text-slate-400">SVP Takamol · Exam Booking &amp; Management Portal</p>
    </footer>
</div>

{{-- Overlay for mobile --}}
<div id="user-overlay" class="fixed inset-0 z-40 bg-slate-900/60 backdrop-blur-sm hidden lg:hidden"></div>

<script>
    const userSidebar = document.getElementById('user-sidebar');
    const userOverlay = document.getElementById('user-overlay');

    function openUserSidebar() {
        userSidebar.classList.remove('-translate-x-full');
        userSidebar.classList.add('translate-x-0');
        userOverlay.classList.remove('hidden');
    }

    function closeUserSidebar() {
        userSidebar.classList.add('-translate-x-full');
        userSidebar.classList.remove('translate-x-0');
        userOverlay.classList.add('hidden');
    }

    if (userOverlay) userOverlay.addEventListener('click', closeUserSidebar);
</script>
</body>
</html>

