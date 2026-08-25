@extends('layouts.user')

@section('title', 'New Deposit')
@section('page-title', 'Deposit Funds')

@section('content')
<div class="space-y-6">
    {{-- ===================== Page header ===================== --}}
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Deposit Funds</h1>
            <p class="text-sm text-slate-500 mt-1">Submit a new deposit request to add funds to your wallet.</p>
        </div>
        <a href="{{ route('user.deposits.index') }}" class="inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl border border-slate-200 bg-white text-slate-700 text-sm font-medium hover:bg-slate-50 hover:border-slate-300 transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            Back to Deposits
        </a>
    </div>

    {{-- ===================== Wallet balance ===================== --}}
    <div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-slate-900 via-indigo-950 to-fuchsia-950 p-6 sm:p-8 text-white shadow-xl shadow-indigo-900/20">
        <div class="absolute -top-20 -right-20 w-64 h-64 bg-indigo-500/25 rounded-full blur-3xl pointer-events-none"></div>
        <div class="absolute -bottom-24 -left-16 w-56 h-56 bg-fuchsia-500/20 rounded-full blur-3xl pointer-events-none"></div>
        <div class="relative">
            <p class="text-sm text-indigo-300 font-medium">Current Wallet Balance</p>
            <p class="text-4xl sm:text-5xl font-extrabold mt-2 tracking-tight">{{ number_format($walletBalance, 2) }} <span class="text-sm font-medium text-slate-400">BDT</span></p>
        </div>
    </div>

    {{-- ===================== Deposit form ===================== --}}
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 sm:p-8">
        <h2 class="text-lg font-bold text-slate-900 mb-1">New Deposit Request</h2>
        <p class="text-sm text-slate-500 mb-6">Send the amount to {{ $merchantName }} using the selected bKash or Nagad method, then submit the transaction details. An admin will verify and approve your deposit.</p>

        <form method="POST" action="{{ route('user.deposits.store') }}" enctype="multipart/form-data" class="space-y-5">
            @csrf
            <div>
                <label for="amount" class="block text-sm font-medium text-slate-700 mb-1.5">Amount (BDT)</label>
                <input type="number" name="amount" id="amount" step="0.01" min="1" value="{{ old('amount') }}" required
                       class="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-sm text-slate-700 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/30 focus:border-indigo-400 transition"
                       placeholder="Enter amount">
                @error('amount') <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="payment_method" class="block text-sm font-medium text-slate-700 mb-1.5">Payment Method</label>
                <select name="payment_method" id="payment_method" required
                        class="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-500/30 focus:border-indigo-400 transition">
                    <option value="" disabled {{ old('payment_method') ? '' : 'selected' }}>Select bKash or Nagad</option>
                    @foreach ($paymentMethods as $method)
                    <option value="{{ $method }}" {{ old('payment_method') === $method ? 'selected' : '' }}>{{ ucfirst($method) }}</option>
                    @endforeach
                </select>
                <p class="mt-1.5 text-xs text-slate-400">Merchant: {{ $merchantName }}. Your deposit remains pending until an administrator verifies it.</p>
                @error('payment_method') <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div>
                    <label for="mfs_sender_phone" class="block text-sm font-medium text-slate-700 mb-1.5">Sender mobile number</label>
                    <input type="text" name="mfs_sender_phone" id="mfs_sender_phone" value="{{ old('mfs_sender_phone') }}" required maxlength="32" placeholder="01XXXXXXXXX"
                           class="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-sm text-slate-700 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/30 focus:border-indigo-400 transition">
                    @error('mfs_sender_phone') <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="mfs_transaction_id" class="block text-sm font-medium text-slate-700 mb-1.5">Transaction ID</label>
                    <input type="text" name="mfs_transaction_id" id="mfs_transaction_id" value="{{ old('mfs_transaction_id') }}" required maxlength="128" placeholder="Enter MFS transaction ID"
                           class="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-sm text-slate-700 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/30 focus:border-indigo-400 transition">
                    @error('mfs_transaction_id') <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <label for="receipt" class="block text-sm font-medium text-slate-700 mb-1.5">Payment Receipt</label>
                <input type="file" name="receipt" id="receipt" accept="image/*,.pdf"
                       class="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-sm text-slate-700 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/30 focus:border-indigo-400 transition">
                <p class="mt-1.5 text-xs text-slate-400">Optional proof image/PDF. Admin approval is required before wallet credit.</p>
                @error('receipt') <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="inline-flex items-center justify-center gap-2 px-5 py-2.5 bg-gradient-to-r from-indigo-500 to-fuchsia-500 hover:from-indigo-600 hover:to-fuchsia-600 text-white text-sm font-semibold rounded-xl shadow-lg shadow-indigo-500/25 transition">
                    Submit Manual MFS Deposit
                </button>
                <a href="{{ route('user.deposits.index') }}" class="px-5 py-2.5 rounded-xl border border-slate-200 bg-white text-slate-700 text-sm font-medium hover:bg-slate-50 hover:border-slate-300 transition">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection
