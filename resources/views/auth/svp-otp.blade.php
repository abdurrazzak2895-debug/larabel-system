<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify OTP — SVP Takamol</title>
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
        .otp-input {
            width: 3.5rem;
            height: 3.5rem;
            text-align: center;
            font-size: 1.5rem;
            font-weight: 700;
        }
    </style>
</head>
<body class="gradient-bg min-h-screen flex items-center justify-center p-4">
    <div class="fixed top-0 left-0 w-[40rem] h-[40rem] bg-indigo-500/20 rounded-full blur-3xl -translate-x-1/2 -translate-y-1/2"></div>
    <div class="fixed bottom-0 right-0 w-[35rem] h-[35rem] bg-fuchsia-500/10 rounded-full blur-3xl translate-x-1/3 translate-y-1/3"></div>

    <div class="w-full max-w-lg relative">
        <div class="glass-card rounded-3xl p-8 lg:p-10 text-white shadow-2xl">
            <div class="flex items-center gap-3 mb-8">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-indigo-400 to-fuchsia-500 flex items-center justify-center font-black">S</div>
                <div>
                    <p class="text-lg font-bold leading-tight">SVP Takamol</p>
                    <p class="text-indigo-300 text-xs">OTP Verification</p>
                </div>
            </div>

            <h2 class="text-2xl font-bold mb-1">Check your email</h2>
            <p class="text-slate-400 text-sm mb-8">
                We sent a one-time passcode to your inbox. Enter it below to complete authentication.
            </p>

            @if (session('status'))
                <div class="mb-6 rounded-xl bg-emerald-500/10 border border-emerald-500/30 p-4 text-sm text-emerald-200">
                    {{ session('status') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="mb-6 rounded-xl bg-red-500/10 border border-red-500/30 p-4 text-sm text-red-200">
                    @foreach ($errors->all() as $error)
                        <p>{{ $error }}</p>
                    @endforeach
                </div>
            @endif

            <form method="POST" action="{{ route('svp.otp.verify') }}" class="space-y-6">
                @csrf

                <div>
                    <label for="otp_code" class="block text-sm font-medium text-slate-300 mb-4">One-Time Passcode</label>
                    <div class="flex gap-3 justify-center">
                        <input type="text" inputmode="numeric" maxlength="1" class="otp-input input-glass rounded-xl text-white" data-otp-digit>
                        <input type="text" inputmode="numeric" maxlength="1" class="otp-input input-glass rounded-xl text-white" data-otp-digit>
                        <input type="text" inputmode="numeric" maxlength="1" class="otp-input input-glass rounded-xl text-white" data-otp-digit>
                        <input type="text" inputmode="numeric" maxlength="1" class="otp-input input-glass rounded-xl text-white" data-otp-digit>
                        <input type="text" inputmode="numeric" maxlength="1" class="otp-input input-glass rounded-xl text-white" data-otp-digit>
                        <input type="text" inputmode="numeric" maxlength="1" class="otp-input input-glass rounded-xl text-white" data-otp-digit>
                    </div>
                    <input type="hidden" id="otp_code" name="otp_code">
                </div>

                <button type="submit"
                    class="glow-btn w-full rounded-xl bg-gradient-to-r from-indigo-500 to-fuchsia-500 py-3.5 text-white font-semibold text-sm hover:from-indigo-600 hover:to-fuchsia-600 transition-all duration-300 transform hover:scale-[1.02] active:scale-[0.98]">
                    Verify & Sign In →
                </button>
            </form>

            <div class="mt-6 pt-6 border-t border-white/10 text-center">
                <form method="POST" action="{{ route('svp.otp.resend') }}" class="inline">
                    @csrf
                    <button type="submit" class="text-sm text-indigo-300 hover:text-indigo-200 transition">
                        Resend OTP
                    </button>
                </form>
                <a href="{{ route('svp.login.form') }}" class="block text-sm text-slate-400 hover:text-white transition mt-2">
                    ← Back to SVP login
                </a>
            </div>
        </div>
    </div>

    <script>
        // Auto-advance between OTP digit boxes
        const digits = document.querySelectorAll('[data-otp-digit]');
        const hidden = document.getElementById('otp_code');

        digits.forEach((input, index) => {
            input.addEventListener('input', () => {
                input.value = input.value.replace(/\D/g, '').slice(0, 1);
                if (input.value && index < digits.length - 1) {
                    digits[index + 1].focus();
                }
                hidden.value = Array.from(digits).map(d => d.value).join('');
            });

            input.addEventListener('keydown', (e) => {
                if (e.key === 'Backspace' && !input.value && index > 0) {
                    digits[index - 1].focus();
                }
            });

            input.addEventListener('paste', (e) => {
                e.preventDefault();
                const pasted = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '').slice(0, digits.length);
                pasted.split('').forEach((char, i) => {
                    digits[i].value = char;
                });
                hidden.value = pasted;
                digits[Math.min(pasted.length, digits.length - 1)].focus();
            });
        });

        hidden.value = '';
    </script>
</body>
</html>
