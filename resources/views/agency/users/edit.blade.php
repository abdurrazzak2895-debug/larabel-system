@extends('layouts.panel')

@section('title', 'Edit User')
@section('page-title', 'Edit User')

@section('content')
<div class="max-w-2xl">
    <div class="flex items-center gap-3 mb-6">
        <a href="{{ route('agency.users.index') }}" class="text-slate-400 hover:text-slate-600 transition">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        </a>
        <div>
            <h2 class="text-xl font-bold text-slate-900">Edit User</h2>
            <p class="text-sm text-slate-500 mt-0.5">Update details for <span class="font-medium text-slate-700">{{ $user->name }}</span>.</p>
        </div>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 mb-6">
        <form method="POST" action="{{ route('agency.users.update', $user) }}" class="space-y-5">
            @csrf
            @method('PUT')

            <div>
                <label for="name" class="block text-sm font-medium text-slate-700 mb-1">Full Name</label>
                <input type="text" name="name" id="name" required value="{{ old('name', $user->name) }}"
                    class="w-full rounded-xl border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
                @error('name')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="email" class="block text-sm font-medium text-slate-700 mb-1">Email</label>
                <input type="email" name="email" id="email" required value="{{ old('email', $user->email) }}"
                    class="w-full rounded-xl border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
                @error('email')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="portal_booking_fee" class="block text-sm font-medium text-slate-700 mb-1">Portal booking fee <span class="text-slate-400">(BDT per booking)</span></label>
                <input type="number" name="portal_booking_fee" id="portal_booking_fee" min="0" step="0.01" value="{{ old('portal_booking_fee', $user->portal_booking_fee) }}" placeholder="Leave blank for agency default"
                    class="w-full rounded-xl border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
                <p class="text-xs text-slate-400 mt-1">This user-specific value overrides the agency/global booking price.</p>
                @error('portal_booking_fee')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
            </div>

            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" name="status" value="1" @checked(old('status', $user->status)) class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                <span class="text-sm text-slate-700">Active</span>
            </label>

            <div class="pt-2 flex items-center gap-3">
                <button type="submit"
                    class="inline-flex items-center gap-2 px-5 py-2.5 bg-gradient-to-r from-indigo-500 to-fuchsia-500 hover:from-indigo-600 hover:to-fuchsia-600 text-white text-sm font-semibold rounded-xl shadow-lg shadow-indigo-500/20 transition">
                    Save Changes
                </button>
                <a href="{{ route('agency.users.index') }}"
                   class="inline-flex items-center gap-2 px-5 py-2.5 bg-white border border-slate-200 text-slate-700 text-sm font-medium rounded-xl hover:bg-slate-50 transition">
                    Cancel
                </a>
            </div>
        </form>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 mb-6">
        <h3 class="text-sm font-semibold text-slate-700 mb-1">User Wallet</h3>
        <p class="text-xs text-slate-400 mb-4">Current available balance: <span class="font-semibold text-slate-700">{{ number_format($user->wallet?->available_balance ?? 0, 2) }} BDT</span>. Positive values credit the user; negative values debit the user.</p>
        <form method="POST" action="{{ route('agency.users.wallet.adjust', $user) }}" class="grid grid-cols-1 sm:grid-cols-3 gap-4 items-end">
            @csrf
            <div>
                <label for="wallet_amount" class="block text-sm font-medium text-slate-700 mb-1">Adjustment</label>
                <input type="number" name="amount" id="wallet_amount" step="0.01" required placeholder="e.g. 100 or -25"
                    class="w-full rounded-xl border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
            </div>
            <div>
                <label for="wallet_reference" class="block text-sm font-medium text-slate-700 mb-1">Reference</label>
                <input type="text" name="reference" id="wallet_reference" maxlength="255" placeholder="Optional reference"
                    class="w-full rounded-xl border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
            </div>
            <button type="submit" class="inline-flex justify-center px-5 py-2.5 bg-slate-800 hover:bg-slate-900 text-white text-sm font-semibold rounded-xl transition">Update Wallet</button>
        </form>
    </div>

    {{-- Reset password --}}
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6">
        <h3 class="text-sm font-semibold text-slate-700 mb-1">Reset Password</h3>
        <p class="text-xs text-slate-400 mb-4">Set a new password for this user.</p>
        <form method="POST" action="{{ route('agency.users.reset-password', $user) }}" class="space-y-5">
            @csrf
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label for="password" class="block text-sm font-medium text-slate-700 mb-1">New Password</label>
                    <input type="password" name="password" id="password" required placeholder="Min. 8 characters"
                        class="w-full rounded-xl border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
                    @error('password')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="password_confirmation" class="block text-sm font-medium text-slate-700 mb-1">Confirm Password</label>
                    <input type="password" name="password_confirmation" id="password_confirmation" required placeholder="Repeat password"
                        class="w-full rounded-xl border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
                </div>
            </div>
            <button type="submit"
                class="inline-flex items-center gap-2 px-5 py-2.5 bg-slate-800 hover:bg-slate-900 text-white text-sm font-semibold rounded-xl transition">
                Reset Password
            </button>
        </form>
    </div>
</div>
@endsection
