<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SVP Login — Takamol API</title>
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
                        <p class="text-indigo-300 text-sm">Real API Authentication</p>
                    </div>
                </div>
                <h1 class="text-4xl font-black leading-tight mb-6">
                    Connect to the<br>
                    <span class="bg-gradient-to-r from-indigo-300 to-fuchsia-300 bg-clip-text text-transparent">Takamol API</span>
                </h1>
                <p class="text-slate-400 leading-relaxed mb-10 max-w-md">
                    Authenticate with your real SVP account. An OTP will be sent to your email.
                    Once verified, your Bearer token is stored securely in your session.
                </p>
                <div class="glass-card rounded-2xl p-4 inline-flex items-center gap-3">
                    <span class="w-2.5 h-2.5 rounded-full bg-emerald-400 animate-pulse"></span>
                    <span class="text-sm text-slate-300">svp-international-api.pacc.sa</span>
                </div>
            </div>

            <!-- Right: SVP login card -->
            <div class="glass-card rounded-3xl p-8 lg:p-10 text-white shadow-2xl">
                <div class="mb-8 lg:hidden flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-indigo-400 to-fuchsia-500 flex items-center justify-center font-black">S</div>
                    <p class="text-lg font-bold">SVP Takamol</p>
                </div>

                <h2 class="text-2xl font-bold mb-1">SVP Account Login</h2>
                <p class="text-slate-400 text-sm mb-8">Step 1 of 2 — enter your SVP credentials</p>

                @if ($errors->any())
                    <div class="mb-6 rounded-xl bg-red-500/10 border border-red-500/30 p-4 text-sm text-red-200">
                        @foreach ($errors->all() as $error)
                            <p>{{ $error }}</p>
                        @endforeach
                    </div>
                @endif

                <form method="POST" action="{{ route('svp.login.attempt') }}" class="space-y-5">
                    @csrf

                    <div>
                        <label for="email" class="block text-sm font-medium text-slate-300 mb-2">SVP Email</label>
                        <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="email"
                            class="input-glass w-full rounded-xl px-4 py-3.5 text-white placeholder-slate-500 text-sm"
                            placeholder="you@example.com">
                    </div>

                    <div>
                        <label for="password" class="block text-sm font-medium text-slate-300 mb-2">Password</label>
                        <input type="password" id="password" name="password" required autocomplete="current-password"
                            class="input-glass w-full rounded-xl px-4 py-3.5 text-white placeholder-slate-500 text-sm"
                            placeholder="••••••••">
                    </div>

                    <div>
                        <label for="otp_method" class="block text-sm font-medium text-slate-300 mb-2">OTP Delivery</label>
                        <select id="otp_method" name="otp_method"
                            class="input-glass w-full rounded-xl px-4 py-3.5 text-white placeholder-slate-500 text-sm">
                            <option value="email" class="bg-slate-900">Email</option>
                            <option value="sms" class="bg-slate-900">SMS</option>
                        </select>
                    </div>

                    <button type="submit"
                        class="glow-btn w-full rounded-xl bg-gradient-to-r from-indigo-500 to-fuchsia-500 py-3.5 text-white font-semibold text-sm hover:from-indigo-600 hover:to-fuchsia-600 transition-all duration-300 transform hover:scale-[1.02] active:scale-[0.98]">
                        Send OTP →
                    </button>
                </form>

                <div class="mt-6 pt-6 border-t border-white/10 text-center space-y-2">
                    <a href="{{ route('login') }}" class="block text-sm text-slate-400 hover:text-white transition">
                        ← Use local admin login instead
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
