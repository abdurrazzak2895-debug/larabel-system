<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SVP Takamol — Exam Booking & Management Portal</title>
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
        .gradient-hero {
            background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 60%, #312e81 100%);
        }
        .glass-card {
            background: rgba(255, 255, 255, 0.06);
            backdrop-filter: blur(15px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            transition: all 0.3s ease;
        }
        .glass-card:hover {
            background: rgba(255, 255, 255, 0.1);
            border-color: rgba(129, 140, 248, 0.4);
            transform: translateY(-4px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
        }
        .endpoint-code {
            font-family: 'JetBrains Mono', 'Fira Code', monospace;
        }
        .gradient-border {
            position: relative;
            background: linear-gradient(to bottom right, #0f172a, #1e1b4b);
        }
        .gradient-border::before {
            content: '';
            position: absolute;
            inset: -1px;
            border-radius: inherit;
            padding: 1px;
            background: linear-gradient(135deg, #6366f1, #d946ef, #6366f1);
            -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
            -webkit-mask-composite: xor;
            mask-composite: exclude;
            pointer-events: none;
        }
    </style>
</head>
<body class="bg-slate-950 min-h-screen text-white">
    <!-- Nav -->
    <nav class="sticky top-0 z-50 bg-slate-900/80 backdrop-blur-xl border-b border-white/5">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-indigo-400 to-fuchsia-500 flex items-center justify-center font-black text-sm shadow-lg">S</div>
                    <div class="leading-tight">
                        <p class="font-bold text-sm">SVP Takamol</p>
                        <p class="text-[11px] text-slate-400">Booking Management Portal</p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <a href="#api" class="hidden sm:block px-4 py-2 text-sm text-slate-300 hover:text-white transition rounded-lg hover:bg-white/5">API Endpoints</a>
                    <a href="/login" class="px-4 py-2 text-sm font-medium bg-gradient-to-r from-indigo-500 to-fuchsia-500 rounded-lg hover:from-indigo-600 hover:to-fuchsia-600 transition shadow-lg shadow-indigo-500/20">Sign In</a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Hero -->
    <header class="gradient-hero relative overflow-hidden">
        <div class="absolute top-0 right-0 w-[30rem] h-[30rem] bg-indigo-500/20 rounded-full blur-3xl"></div>
        <div class="absolute bottom-0 left-0 w-[25rem] h-[25rem] bg-fuchsia-500/10 rounded-full blur-3xl"></div>
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-20 lg:py-28 relative">
            <div class="max-w-3xl">
                <div class="inline-flex items-center gap-2 glass-card rounded-full px-4 py-1.5 text-xs text-indigo-200 mb-6">
                    <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                    Live · Connected to Takamol API
                </div>
                <h1 class="text-4xl lg:text-6xl font-black leading-tight tracking-tight mb-6">
                    Exam Booking &
                    <span class="bg-gradient-to-r from-indigo-300 via-fuchsia-300 to-indigo-300 bg-clip-text text-transparent">Management Platform</span>
                </h1>
                <p class="text-lg text-slate-400 leading-relaxed mb-10 max-w-2xl">
                    A complete Laravel integration with the SVP International Takamol exam system. Manage agencies, wallets, bookings, deposits, refunds and notifications — all through one premium interface.
                </p>
                <div class="flex flex-wrap gap-4">
                    <a href="/login" class="px-6 py-3 rounded-xl bg-gradient-to-r from-indigo-500 to-fuchsia-500 font-semibold text-sm hover:from-indigo-600 hover:to-fuchsia-600 transition shadow-xl shadow-indigo-500/25">
                        Get Started →
                    </a>
                    <a href="#api" class="px-6 py-3 rounded-xl glass-card font-semibold text-sm hover:bg-white/10 transition">
                        Explore API
                    </a>
                </div>
            </div>
        </div>
    </header>

    <!-- Platform cards -->
    <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
        <div class="grid md:grid-cols-3 gap-6">
            <a href="/admin/dashboard" class="gradient-border rounded-2xl p-6 hover:scale-[1.02] transition-transform duration-300">
                <div class="flex items-center justify-between mb-4">
                    <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-indigo-500 to-indigo-700 flex items-center justify-center text-xl">🛠️</div>
                    <span class="text-xs px-3 py-1 rounded-full bg-indigo-500/10 text-indigo-300 border border-indigo-500/30">Admin</span>
                </div>
                <h3 class="text-lg font-bold mb-2">Admin Panel</h3>
                <p class="text-sm text-slate-400 leading-relaxed mb-4">Manage agencies, wallet balances, approve deposits & refunds, and monitor every booking in real time.</p>
                <span class="inline-flex items-center gap-2 text-sm font-medium text-indigo-300">
                    Open Admin
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                </span>
            </a>

            <a href="/agency/dashboard" class="gradient-border rounded-2xl p-6 hover:scale-[1.02] transition-transform duration-300">
                <div class="flex items-center justify-between mb-4">
                    <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-fuchsia-500 to-fuchsia-700 flex items-center justify-center text-xl">🏢</div>
                    <span class="text-xs px-3 py-1 rounded-full bg-fuchsia-500/10 text-fuchsia-300 border border-fuchsia-500/30">Agency</span>
                </div>
                <h3 class="text-lg font-bold mb-2">Agency Panel</h3>
                <p class="text-sm text-slate-400 leading-relaxed mb-4">Submit deposit & refund requests, track wallet balance, and monitor your agency's booking activity.</p>
                <span class="inline-flex items-center gap-2 text-sm font-medium text-fuchsia-300">
                    Open Agency
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                </span>
            </a>

            <div class="gradient-border rounded-2xl p-6">
                <div class="flex items-center justify-between mb-4">
                    <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-700 flex items-center justify-center text-xl">🔌</div>
                    <span class="text-xs px-3 py-1 rounded-full bg-emerald-500/10 text-emerald-300 border border-emerald-500/30">API v1</span>
                </div>
                <h3 class="text-lg font-bold mb-2">Live API Connection</h3>
                <p class="text-sm text-slate-400 leading-relaxed mb-4">All 12 production endpoints verified against the real SVP/Takamol system — returning 200 OK.</p>
                <span class="inline-flex items-center gap-2 text-sm font-medium text-emerald-300">
                    <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                    Status: Operational
                </span>
            </div>
        </div>
    </section>

    <!-- API Endpoints highlighted -->
    <section id="api" class="relative bg-slate-900/40 border-y border-white/5 py-16">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-12">
                <p class="text-indigo-300 text-sm font-semibold uppercase tracking-wider mb-2">RESTful Integration</p>
                <h2 class="text-3xl lg:text-4xl font-black">API Endpoints</h2>
                <p class="text-slate-400 mt-3 max-w-2xl mx-auto">Proxy endpoints through our Laravel app to the external Takamol booking system. Paste your Bearer token and call — we forward everything upstream.</p>
            </div>

            <div class="grid lg:grid-cols-2 gap-4">
                <!-- Authentication -->
                <div class="glass-card rounded-2xl p-6">
                    <div class="flex items-center gap-2 mb-4">
                        <span class="text-lg">🔐</span>
                        <h3 class="font-bold">Authentication</h3>
                    </div>
                    <div class="space-y-3">
                        <div class="rounded-xl bg-slate-900/60 border border-white/5 p-4">
                            <div class="flex items-center gap-2 mb-1.5">
                                <span class="px-2 py-0.5 rounded text-[11px] font-bold bg-emerald-500/15 text-emerald-400 border border-emerald-500/30">POST</span>
                                <span class="endpoint-code text-xs text-slate-300">/api/v1/sessions/login</span>
                            </div>
                            <p class="text-xs text-slate-500">Login with user credentials & receive OTP</p>
                        </div>
                        <div class="rounded-xl bg-slate-900/60 border border-white/5 p-4">
                            <div class="flex items-center gap-2 mb-1.5">
                                <span class="px-2 py-0.5 rounded text-[11px] font-bold bg-emerald-500/15 text-emerald-400 border border-emerald-500/30">POST</span>
                                <span class="endpoint-code text-xs text-slate-300">/api/v1/sessions/otp</span>
                            </div>
                            <p class="text-xs text-slate-500">Verify OTP & receive Bearer token</p>
                        </div>
                    </div>
                </div>

                <!-- Profile & Permissions -->
                <div class="glass-card rounded-2xl p-6">
                    <div class="flex items-center gap-2 mb-4">
                        <span class="text-lg">👤</span>
                        <h3 class="font-bold">Profile & Permissions</h3>
                    </div>
                    <div class="space-y-3">
                        <div class="rounded-xl bg-slate-900/60 border border-white/5 p-4">
                            <div class="flex items-center gap-2 mb-1.5">
                                <span class="px-2 py-0.5 rounded text-[11px] font-bold bg-sky-500/15 text-sky-400 border border-sky-500/30">GET</span>
                                <span class="endpoint-code text-xs text-slate-300">/individual_labor_space/profile</span>
                            </div>
                            <p class="text-xs text-slate-500">Current user profile & country</p>
                        </div>
                        <div class="rounded-xl bg-slate-900/60 border border-white/5 p-4">
                            <div class="flex items-center gap-2 mb-1.5">
                                <span class="px-2 py-0.5 rounded text-[11px] font-bold bg-sky-500/15 text-sky-400 border border-sky-500/30">GET</span>
                                <span class="endpoint-code text-xs text-slate-300">/individual_labor_space/permissions</span>
                            </div>
                            <p class="text-xs text-slate-500">labor-space permissions matrix</p>
                        </div>
                        <div class="rounded-xl bg-slate-900/60 border border-white/5 p-4">
                            <div class="flex items-center gap-2 mb-1.5">
                                <span class="px-2 py-0.5 rounded text-[11px] font-bold bg-sky-500/15 text-sky-400 border border-sky-500/30">GET</span>
                                <span class="endpoint-code text-xs text-slate-300">/individual_labor_space/certificate_price</span>
                            </div>
                            <p class="text-xs text-slate-500">Certificate/service pricing</p>
                        </div>
                    </div>
                </div>

                <!-- Exam & Booking -->
                <div class="glass-card rounded-2xl p-6">
                    <div class="flex items-center gap-2 mb-4">
                        <span class="text-lg">📋</span>
                        <h3 class="font-bold">Exam & Booking</h3>
                    </div>
                    <div class="space-y-3">
                        <div class="rounded-xl bg-slate-900/60 border border-white/5 p-4">
                            <div class="flex items-center gap-2 mb-1.5">
                                <span class="px-2 py-0.5 rounded text-[11px] font-bold bg-sky-500/15 text-sky-400 border border-sky-500/30">GET</span>
                                <span class="endpoint-code text-xs text-slate-300">/individual_labor_space/exam_sessions</span>
                            </div>
                            <p class="text-xs text-slate-500">List available exam sessions</p>
                        </div>
                        <div class="rounded-xl bg-slate-900/60 border border-white/5 p-4">
                            <div class="flex items-center gap-2 mb-1.5">
                                <span class="px-2 py-0.5 rounded text-[11px] font-bold bg-sky-500/15 text-sky-400 border border-sky-500/30">GET</span>
                                <span class="endpoint-code text-xs text-slate-300">/individual_labor_space/exam_sessions/available_dates</span>
                            </div>
                            <p class="text-xs text-slate-500">Available exam dates & centers</p>
                        </div>
                        <div class="rounded-xl bg-slate-900/60 border border-white/5 p-4">
                            <div class="flex items-center gap-2 mb-1.5">
                                <span class="px-2 py-0.5 rounded text-[11px] font-bold bg-sky-500/15 text-sky-400 border border-sky-500/30">GET</span>
                                <span class="endpoint-code text-xs text-slate-300">/individual_labor_space/exam_reservations</span>
                            </div>
                            <p class="text-xs text-slate-500">User's exam reservations</p>
                        </div>
                        <div class="rounded-xl bg-slate-900/60 border border-white/5 p-4">
                            <div class="flex items-center gap-2 mb-1.5">
                                <span class="px-2 py-0.5 rounded text-[11px] font-bold bg-sky-500/15 text-sky-400 border border-sky-500/30">GET</span>
                                <span class="endpoint-code text-xs text-slate-300">/individual_labor_space/exam_reservations/validate</span>
                            </div>
                            <p class="text-xs text-slate-500">Validate a reservation</p>
                        </div>
                    </div>
                </div>

                <!-- Payment / Notification / Verification -->
                <div class="glass-card rounded-2xl p-6">
                    <div class="flex items-center gap-2 mb-4">
                        <span class="text-lg">💳</span>
                        <h3 class="font-bold">Payment, Notification & Verification</h3>
                    </div>
                    <div class="space-y-3">
                        <div class="rounded-xl bg-slate-900/60 border border-white/5 p-4">
                            <div class="flex items-center gap-2 mb-1.5">
                                <span class="px-2 py-0.5 rounded text-[11px] font-bold bg-sky-500/15 text-sky-400 border border-sky-500/30">GET</span>
                                <span class="endpoint-code text-xs text-slate-300">/individual_labor_space/payments/validate_pending</span>
                            </div>
                            <p class="text-xs text-slate-500">Validate pending payment</p>
                        </div>
                        <div class="rounded-xl bg-slate-900/60 border border-white/5 p-4">
                            <div class="flex items-center gap-2 mb-1.5">
                                <span class="px-2 py-0.5 rounded text-[11px] font-bold bg-sky-500/15 text-sky-400 border border-sky-500/30">GET</span>
                                <span class="endpoint-code text-xs text-slate-300">/individual_labor_space/notifications</span>
                            </div>
                            <p class="text-xs text-slate-500">User notifications & alerts</p>
                        </div>
                        <div class="rounded-xl bg-slate-900/60 border border-white/5 p-4">
                            <div class="flex items-center gap-2 mb-1.5">
                                <span class="px-2 py-0.5 rounded text-[11px] font-bold bg-sky-500/15 text-sky-400 border border-sky-500/30">GET</span>
                                <span class="endpoint-code text-xs text-slate-300">/individual_labor_space/verification_requests</span>
                            </div>
                            <p class="text-xs text-slate-500">Verification request history</p>
                        </div>
                    </div>
                </div>

                <!-- Lookups -->
                <div class="glass-card rounded-2xl p-6">
                    <div class="flex items-center gap-2 mb-4">
                        <span class="text-lg">🗂️</span>
                        <h3 class="font-bold">Lookups</h3>
                    </div>
                    <div class="space-y-3">
                        <div class="rounded-xl bg-slate-900/60 border border-white/5 p-4">
                            <div class="flex items-center gap-2 mb-1.5">
                                <span class="px-2 py-0.5 rounded text-[11px] font-bold bg-sky-500/15 text-sky-400 border border-sky-500/30">GET</span>
                                <span class="endpoint-code text-xs text-slate-300">/individual_labor_space/occupations</span>
                            </div>
                            <p class="text-xs text-slate-500">List occupations (2279+)</p>
                        </div>
                        <div class="rounded-xl bg-slate-900/60 border border-white/5 p-4">
                            <div class="flex items-center gap-2 mb-1.5">
                                <span class="px-2 py-0.5 rounded text-[11px] font-bold bg-sky-500/15 text-sky-400 border border-sky-500/30">GET</span>
                                <span class="endpoint-code text-xs text-slate-300">/individual_labor_space/cities</span>
                            </div>
                            <p class="text-xs text-slate-500">List cities</p>
                        </div>
                        <div class="rounded-xl bg-slate-900/60 border border-white/5 p-4">
                            <div class="flex items-center gap-2 mb-1.5">
                                <span class="px-2 py-0.5 rounded text-[11px] font-bold bg-sky-500/15 text-sky-400 border border-sky-500/30">GET</span>
                                <span class="endpoint-code text-xs text-slate-300">/individual_labor_space/categories</span>
                            </div>
                            <p class="text-xs text-slate-500">List exam categories</p>
                        </div>
                        <div class="rounded-xl bg-slate-900/60 border border-white/5 p-4">
                            <div class="flex items-center gap-2 mb-1.5">
                                <span class="px-2 py-0.5 rounded text-[11px] font-bold bg-sky-500/15 text-sky-400 border border-sky-500/30">GET</span>
                                <span class="endpoint-code text-xs text-slate-300">/individual_labor_space/exam_constraints</span>
                            </div>
                            <p class="text-xs text-slate-500">Exam constraints & limits</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="border-t border-white/5 py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center text-sm text-slate-500">
            <p>SVP Takamol — Exam Management & Booking API Integration · Laravel</p>
            <p class="mt-1 text-xs">All API proxies verified against the live Takamol system</p>
        </div>
    </footer>
</body>
</html>
