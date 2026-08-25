@extends('layouts.panel')

@section('title', 'New Deposit')
@section('page-title', 'Submit Deposit')

@section('content')
<div class="max-w-2xl">
    <div class="flex items-center gap-3 mb-6">
        <a href="{{ route('agency.deposits.index') }}" class="text-slate-400 hover:text-slate-600 transition">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        </a>
        <div>
            <h2 class="text-xl font-bold text-slate-900">Submit Deposit</h2>
            <p class="text-sm text-slate-500 mt-0.5">Submit a deposit for a user wallet. The agency wallet is not used for user bookings.</p>
        </div>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6">
        <form method="POST" action="{{ route('agency.deposits.store') }}" enctype="multipart/form-data" class="space-y-5">
            @csrf

            <div>
                <label for="user_id" class="block text-sm font-medium text-slate-700 mb-1">User</label>
                <select name="user_id" id="user_id" required class="w-full rounded-xl border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
                    <option value="">Select user…</option>
                    @foreach ($users as $user)
                        <option value="{{ $user->id }}" @selected(old('user_id') == $user->id)>{{ $user->name }} — {{ $user->email }}</option>
                    @endforeach
                </select>
                @error('user_id')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="amount" class="block text-sm font-medium text-slate-700 mb-1">Amount <span class="text-slate-400">(BDT)</span></label>
                <div class="relative">
                    <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 text-sm font-medium">BDT</span>
                    <input type="number" name="amount" id="amount" step="0.01" min="1" required value="{{ old('amount') }}"
                        placeholder="0.00"
                        class="w-full rounded-xl pl-14 pr-4 py-3 border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
                </div>
                @error('amount')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="payment_method" class="block text-sm font-medium text-slate-700 mb-1">Payment Method</label>
                <select name="payment_method" id="payment_method" required
                    class="w-full rounded-xl border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
                    <option value="">Select payment method…</option>
                    <option value="bank_transfer" @selected(old('payment_method') === 'bank_transfer')>Bank Transfer</option>
                    <option value="cash" @selected(old('payment_method') === 'cash')>Cash</option>
                    <option value="card" @selected(old('payment_method') === 'card')>Card Payment</option>
                    <option value="other" @selected(old('payment_method') === 'other')>Other</option>
                </select>
                @error('payment_method')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="receipt" class="block text-sm font-medium text-slate-700 mb-1">Receipt <span class="text-slate-400 font-normal">(optional, jpg/png/pdf)</span></label>
                <input type="file" name="receipt" id="receipt" accept="image/*,.pdf"
                    class="w-full rounded-xl border-slate-200 text-sm file:mr-4 file:py-2.5 file:px-4 file:rounded-lg file:border-0 file:bg-indigo-50 file:text-indigo-700 file:text-sm file:font-medium hover:file:bg-indigo-100 focus:border-brand-500 focus:ring-brand-500">
                @error('receipt')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
            </div>

            <div class="pt-2 flex items-center gap-3">
                <button type="submit"
                    class="inline-flex items-center gap-2 px-5 py-2.5 bg-gradient-to-r from-indigo-500 to-fuchsia-500 hover:from-indigo-600 hover:to-fuchsia-600 text-white text-sm font-semibold rounded-xl shadow-lg shadow-indigo-500/20 transition">
                    Submit Deposit
                </button>
                <a href="{{ route('agency.deposits.index') }}"
                   class="inline-flex items-center gap-2 px-5 py-2.5 bg-white border border-slate-200 text-slate-700 text-sm font-medium rounded-xl hover:bg-slate-50 transition">
                    Cancel
                </a>
            </div>
        </form>
    </div>
</div>
@endsection
