@extends('layouts.user')

@section('title', 'Add Funds')
@section('page-title', 'Add Funds')

@section('content')
<div class="max-w-3xl mx-auto space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Add Funds</h1>
            <p class="text-sm text-slate-500 mt-1">Send the amount to one of the merchant numbers below, then submit your transaction details.</p>
        </div>
        <a href="{{ route('user.deposits.index') }}" class="inline-flex items-center justify-center px-4 py-2.5 rounded-xl border border-slate-200 bg-white text-slate-700 text-sm font-semibold hover:bg-slate-50 transition">Deposit History</a>
    </div>

    @if (session('success'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ session('error') }}</div>
    @endif
    @if ($errors->any())
        <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            <p class="font-semibold">Please correct the following:</p>
            <ul class="list-disc list-inside mt-1 space-y-0.5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="rounded-2xl border border-indigo-200 bg-indigo-50 p-5">
        <h2 class="text-base font-semibold text-indigo-950">Payment instructions</h2>
        <p class="text-sm text-indigo-800 mt-1">Pay from your own bKash or Nagad account to the configured {{ $merchantName }} merchant number. Keep the transaction ID and sender number ready.</p>
        <div class="grid sm:grid-cols-2 gap-3 mt-4">
            @foreach ($paymentMethods as $method)
                <div class="rounded-xl border border-indigo-200 bg-white px-4 py-3">
                    <p class="text-xs uppercase tracking-wide text-slate-500">{{ $method === 'bkash' ? 'bKash' : 'Nagad' }}</p>
                    <p class="font-mono font-semibold text-slate-900 mt-1">{{ $merchantNumbers[$method] ?: 'Not configured' }}</p>
                </div>
            @endforeach
        </div>
    </div>

    <form method="POST" action="{{ route('user.deposits.store') }}" enctype="multipart/form-data" class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 space-y-5">
        @csrf
        <div class="grid sm:grid-cols-2 gap-5">
            <div>
                <label for="amount" class="block text-sm font-medium text-slate-700 mb-1.5">Amount (BDT)</label>
                <input type="number" name="amount" id="amount" value="{{ old('amount') }}" min="1" step="0.01" required class="w-full rounded-xl border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
                @error('amount')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="payment_method" class="block text-sm font-medium text-slate-700 mb-1.5">Payment method</label>
                <select name="payment_method" id="payment_method" required class="w-full rounded-xl border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
                    @foreach ($paymentMethods as $method)
                        <option value="{{ $method }}" @selected(old('payment_method') === $method)>{{ $method === 'bkash' ? 'bKash' : 'Nagad' }}</option>
                    @endforeach
                </select>
                @error('payment_method')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="mfs_sender_phone" class="block text-sm font-medium text-slate-700 mb-1.5">Sender mobile number</label>
                <input type="text" name="mfs_sender_phone" id="mfs_sender_phone" value="{{ old('mfs_sender_phone') }}" placeholder="01XXXXXXXXX" required class="w-full rounded-xl border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
                @error('mfs_sender_phone')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="mfs_transaction_id" class="block text-sm font-medium text-slate-700 mb-1.5">Transaction ID</label>
                <input type="text" name="mfs_transaction_id" id="mfs_transaction_id" value="{{ old('mfs_transaction_id') }}" required class="w-full rounded-xl border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
                @error('mfs_transaction_id')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
            </div>
        </div>
        <div>
            <label for="receipt" class="block text-sm font-medium text-slate-700 mb-1.5">Payment proof <span class="font-normal text-slate-400">(optional)</span></label>
            <input type="file" name="receipt" id="receipt" accept=".jpg,.jpeg,.png,.pdf" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-xs file:font-semibold">
            <p class="text-xs text-slate-400 mt-1">JPG, PNG, or PDF up to 4 MB.</p>
            @error('receipt')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
        </div>
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pt-2">
            <p class="text-xs text-slate-500">Your request stays pending until Admin Control verifies and approves it.</p>
            <button type="submit" class="inline-flex items-center justify-center px-5 py-2.5 rounded-xl bg-gradient-to-r from-indigo-500 to-fuchsia-500 text-white text-sm font-semibold shadow-lg shadow-indigo-500/20 hover:from-indigo-600 hover:to-fuchsia-600 transition">Submit Deposit Request</button>
        </div>
    </form>
</div>
@endsection
