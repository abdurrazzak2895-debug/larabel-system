<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account — SVP Takamol Portal</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Inter', system-ui, -apple-system, sans-serif; }
        .gradient-bg { background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 50%, #312e81 100%); }
        .glass-card { background: rgba(255, 255, 255, 0.08); backdrop-filter: blur(20px); border: 1px solid rgba(255, 255, 255, 0.12); }
        .input-glass { background: rgba(255, 255, 255, 0.06); border: 1px solid rgba(255, 255, 255, 0.15); transition: all 0.3s ease; }
        .input-glass:focus { border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.25); outline: none; }
    </style>
</head>
<body class="gradient-bg min-h-screen flex items-center justify-center p-4">
    <div class="fixed top-0 left-0 w-[40rem] h-[40rem] bg-indigo-500/20 rounded-full blur-3xl -translate-x-1/2 -translate-y-1/2"></div>
    <div class="fixed bottom-0 right-0 w-[35rem] h-[35rem] bg-fuchsia-500/10 rounded-full blur-3xl translate-x-1/3 translate-y-1/3"></div>

    <div class="w-full max-w-2xl relative">
        <div class="glass-card rounded-3xl p-8 sm:p-10 text-white shadow-2xl">
            <div class="flex items-center gap-3 mb-8">
                <div class="w-11 h-11 rounded-2xl bg-gradient-to-br from-indigo-400 to-fuchsia-500 flex items-center justify-center text-lg font-black shadow-lg">S</div>
                <div>
                    <p class="text-lg font-bold leading-tight">SVP Takamol</p>
                    <p class="text-indigo-300 text-xs">Booking Management Portal</p>
                </div>
            </div>

            <h1 class="text-2xl font-bold mb-1">Create your account</h1>
            <p class="text-slate-400 text-sm mb-8">Register as an agency user to access your wallet and booking dashboard.</p>

            @if ($errors->any())
                <div class="mb-6 rounded-xl bg-red-500/10 border border-red-500/30 p-4 text-sm text-red-200 space-y-1">
                    @foreach ($errors->all() as $error)
                        <p>{{ $error }}</p>
                    @endforeach
                </div>
            @endif

            <form method="POST" action="{{ route('register.store') }}" class="space-y-5">
                @csrf
                <div class="grid sm:grid-cols-2 gap-5">
                    <div>
                        <label for="name" class="block text-sm font-medium text-slate-300 mb-2">Full name</label>
                        <input type="text" id="name" name="name" value="{{ old('name') }}" required autofocus autocomplete="name" class="input-glass w-full rounded-xl px-4 py-3.5 text-white placeholder-slate-500 text-sm" placeholder="Your full name">
                    </div>
                    <div>
                        <label for="username" class="block text-sm font-medium text-slate-300 mb-2">Username</label>
                        <input type="text" id="username" name="username" value="{{ old('username') }}" required autocomplete="username" class="input-glass w-full rounded-xl px-4 py-3.5 text-white placeholder-slate-500 text-sm" placeholder="Choose a username">
                    </div>
                </div>

                <div>
                    <label for="email" class="block text-sm font-medium text-slate-300 mb-2">Email address</label>
                    <input type="email" id="email" name="email" value="{{ old('email') }}" required autocomplete="email" class="input-glass w-full rounded-xl px-4 py-3.5 text-white placeholder-slate-500 text-sm" placeholder="you@example.com">
                </div>

                <div>
                    <label for="agency_code" class="block text-sm font-medium text-slate-300 mb-2">Agency</label>
                    <input type="hidden" id="agency_code" name="agency_code" value="SVP-7474">
                    <div class="input-glass w-full rounded-xl px-4 py-3.5 text-white text-sm">SVP-7474</div>
                    <p class="mt-2 text-xs text-slate-400">All new portal accounts are registered under the SVP-7474 agency.</p>
                    @error('agency_code') <p class="mt-1.5 text-xs text-red-200">{{ $message }}</p> @enderror
                </div>

                <div class="grid sm:grid-cols-2 gap-5">
                    <div>
                        <label for="password" class="block text-sm font-medium text-slate-300 mb-2">Password</label>
                        <input type="password" id="password" name="password" required autocomplete="new-password" class="input-glass w-full rounded-xl px-4 py-3.5 text-white placeholder-slate-500 text-sm" placeholder="At least 8 characters">
                    </div>
                    <div>
                        <label for="password_confirmation" class="block text-sm font-medium text-slate-300 mb-2">Confirm password</label>
                        <input type="password" id="password_confirmation" name="password_confirmation" required autocomplete="new-password" class="input-glass w-full rounded-xl px-4 py-3.5 text-white placeholder-slate-500 text-sm" placeholder="Repeat your password">
                    </div>
                </div>

                <button type="submit" class="w-full rounded-xl bg-gradient-to-r from-indigo-500 to-fuchsia-500 py-3.5 text-white font-semibold text-sm shadow-lg shadow-indigo-500/30 hover:from-indigo-600 hover:to-fuchsia-600 transition-all">
                    Create Account →
                </button>
            </form>

            <div class="mt-8 pt-6 border-t border-white/10 text-center">
                <p class="text-sm text-slate-400">Already have an account?
                    <a href="{{ route('login') }}" class="text-indigo-300 hover:text-indigo-200 font-medium transition">Sign in here</a>
                </p>
            </div>
        </div>
    </div>
</body>
</html>
