<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In — SVP Takamol Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: {
                            50: '#eef2ff',
                            100: '#e0e7ff',
                            500: '#6366f1',
                            600: '#4f46e5',
                            700: '#4338ca',
                            800: '#3730a3',
                            900: '#312e81',
                        }
                    }
                }
            }
        }
    </script>
    <style>
        body { font-family: 'Inter', system-ui, -apple-system, sans-serif; }
        .gradient-bg {
            background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 50%, #312e81 100%);
        }
        .glass-card {
            background: rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.12);
        }
        .glow-btn {
            box-shadow: 0 0 25px rgba(99, 102, 241, 0.35);
        }
        .input-glass {
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.15);
            transition: all 0.3s ease;
        }
        .input-glass:focus {
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.25);
            outline: none;
        }
    </style>
</head>
<body class="gradient-bg min-h-screen flex items-center justify-center p-4">
    <!-- Decorative blobs -->
    <div class="fixed top-0 left-0 w-[40rem] h-[40rem] bg-indigo-500/20 rounded-full blur-3xl -translate-x-1/2 -translate-y-1/2"></div>
    <div class="fixed bottom-0 right-0 w-[35rem] h-[35rem] bg-fuchsia-500/10 rounded-full blur-3xl translate-x-1/3 translate-y-1/3"></div>

    <div class="w-full max-w-5xl relative">
        <div class="grid lg:grid-cols-2 gap-8 items-center">
            <!-- Left -->
            <div class="hidden lg:block text-white">
                <div class="flex items-center gap-3 mb-8">
                    <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-indigo-400 to-fuchsia-500 flex items-center justify-center text-xl font-black shadow-lg">S</div>
                    <div>
                        <p class="text-xl font-bold leading-tight">SVP Takamol</p>
                        <p class="text-indigo-300 text-sm">Booking Management Portal</p>
                    </div>
                </div>
                <h1 class="text-4xl font-black leading-tight mb-6">
                    Seamless Exam<br>
                    <span class="bg-gradient-to-r from-indigo-300 to-fuchsia-300 bg-clip-text text-transparent">Booking & Management</span>
                </h1>
                <p class="text-slate-400 leading-relaxed mb-10 max-w-md">
                    Administer agencies, wallets, exam bookings and payments — seamlessly connected to the Takamol international exam API.
                </p>
                <div class="grid grid-cols-3 gap-4 max-w-md">
                    <div class="glass-card rounded-2xl p-4 text-center">
                        <p class="text-2xl font-bold text-emerald-400">12+</p>
                        <p class="text-xs text-slate-400 mt-1">API Endpoints</p>
                    </div>
                    <div class="glass-card rounded-2xl p-4 text-center">
                        <p class="text-2xl font-bold text-indigo-300">100%</p>
                        <p class="text-xs text-slate-400 mt-1">Live Connected</p>
                    </div>
                    <div class="glass-card rounded-2xl p-4 text-center">
                        <p class="text-2xl font-bold text-fuchsia-300">24/7</p>
                        <p class="text-xs text-slate-400 mt-1">Available</p>
                    </div>
                </div>
            </div>

            <!-- Right: Login card -->
            <div class="glass-card rounded-3xl p-8 lg:p-10 text-white shadow-2xl">
                <div class="mb-8 lg:hidden flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-indigo-400 to-fuchsia-500 flex items-center justify-center font-black">S</div>
                    <p class="text-lg font-bold">SVP Takamol</p>
                </div>

                <h2 class="text-2xl font-bold mb-1">Welcome back</h2>
                <p class="text-slate-400 text-sm mb-8">Sign in to access your dashboard</p>

                @if ($errors->any())
                    <div class="mb-6 rounded-xl bg-red-500/10 border border-red-500/30 p-4 text-sm text-red-200">
                        @foreach ($errors->all() as $error)
                            <p>{{ $error }}</p>
                        @endforeach
                    </div>
                @endif

                @if (session('status'))
                    <div class="mb-6 rounded-xl bg-emerald-500/10 border border-emerald-500/30 p-4 text-sm text-emerald-200">
                        {{ session('status') }}
                    </div>
                @endif

                <form method="POST" action="{{ route('login.attempt') }}" class="space-y-5">
                    @csrf

                    <div>
                        <label for="login" class="block text-sm font-medium text-slate-300 mb-2">Username or Email</label>
                        <div class="relative">
                            <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-500">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-4.5L21 21"/>
                                </svg>
                            </span>
                            <input type="text" id="login" name="login" value="{{ old('login') }}" required autofocus autocomplete="username"
                                class="input-glass w-full rounded-xl pl-12 pr-4 py-3.5 text-white placeholder-slate-500 text-sm"
                                placeholder="admin">
                        </div>
                    </div>

                    <div>
                        <label for="password" class="block text-sm font-medium text-slate-300 mb-2">Password</label>
                        <div class="relative">
                            <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-500">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm2-10V7a4 4 0 018 0v4"/>
                                </svg>
                            </span>
                            <input type="password" id="password" name="password" required autocomplete="current-password"
                                class="input-glass w-full rounded-xl pl-12 pr-4 py-3.5 text-white placeholder-slate-500 text-sm"
                                placeholder="••••••••">
                        </div>
                    </div>

                    <div class="flex items-center justify-between text-sm">
                        <label class="flex items-center gap-2 text-slate-400 cursor-pointer">
                            <input type="checkbox" name="remember" value="1" class="rounded border-slate-600 bg-slate-800 text-indigo-500 focus:ring-indigo-500">
                            Remember me
                        </label>
                        <a href="#" class="text-indigo-300 hover:text-indigo-200 transition">Forgot password?</a>
                    </div>

                    <button type="submit"
                        class="glow-btn w-full rounded-xl bg-gradient-to-r from-indigo-500 to-fuchsia-500 py-3.5 text-white font-semibold text-sm hover:from-indigo-600 hover:to-fuchsia-600 transition-all duration-300 transform hover:scale-[1.02] active:scale-[0.98]">
                        Sign In →
                    </button>

                    <div class="rounded-xl border border-white/10 bg-white/5 p-4 text-xs text-slate-400 space-y-1">
                        <p class="font-semibold text-slate-300 uppercase tracking-wide">Demo logins</p>
                        <p>Admin &nbsp;<code class="text-indigo-300">admin@takamol.example.com</code> / <code class="text-indigo-300">ChangeMe123!</code></p>
                        <p>Agency &nbsp;<code class="text-emerald-300">alnoor</code> / <code class="text-emerald-300">password</code></p>
                    </div>
                </form>

                <div class="mt-8 pt-6 border-t border-white/10 text-center space-y-3">
                    <a href="{{ route('svp.login.form') }}" class="block text-sm text-indigo-300 hover:text-indigo-200 transition">
                        Login with real SVP / Takamol account →
                    </a>
                    <a href="/" class="text-sm text-slate-400 hover:text-white transition inline-flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                        </svg>
                        Back to Portal
                    </a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
