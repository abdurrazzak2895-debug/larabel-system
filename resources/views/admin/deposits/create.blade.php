@extends('layouts.panel')

@section('title', 'Create Deposit')
@section('page-title', 'Create User Deposit')

@section('content')
<div class="max-w-3xl space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h2 class="text-xl font-bold text-slate-900">Create User Deposit</h2>
            <p class="text-sm text-slate-500 mt-1">Admin-only manual MFS deposit for an existing or newly registered user.</p>
        </div>
        <a href="{{ route('admin.deposits.index') }}" class="inline-flex items-center justify-center px-4 py-2.5 rounded-xl border border-slate-200 bg-white text-slate-700 text-sm font-medium hover:bg-slate-50 transition">Back to Deposits</a>
    </div>

    <div class="rounded-2xl border border-indigo-200 bg-indigo-50 p-5 text-sm text-indigo-900">
        <p class="font-semibold">{{ $merchantName }}</p>
        <p class="mt-1">Private payment numbers are displayed only in this Admin Control form.</p>
        <div class="grid sm:grid-cols-2 gap-3 mt-4">
            @foreach ($paymentMethods as $method)
                <div class="rounded-xl border border-indigo-200 bg-white px-4 py-3">
                    <p class="text-xs uppercase tracking-wide text-slate-500">{{ $method === 'bkash' ? 'bKash' : 'Nagad' }}</p>
                    <p class="font-mono font-semibold text-slate-900 mt-1">{{ $merchantNumbers[$method] ?: 'Not configured' }}</p>
                </div>
            @endforeach
        </div>
    </div>

    @if ($errors->any())
        <div class="rounded-xl bg-red-50 border border-red-200 p-4 text-sm text-red-700 space-y-1">
            @foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach
        </div>
    @endif

    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 sm:p-8">
        <form method="POST" action="{{ route('admin.deposits.store') }}" enctype="multipart/form-data" class="space-y-5">
            @csrf
            <div class="grid sm:grid-cols-2 gap-5">
                <div>
                    <label for="agency_id" class="block text-sm font-medium text-slate-700 mb-1.5">Agency</label>
                    <select name="agency_id" id="agency_id" required class="w-full rounded-xl border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
                        <option value="">Select agency</option>
                        @foreach ($agencies as $agency)
                            <option value="{{ $agency->id }}" @selected(old('agency_id') == $agency->id)>{{ $agency->name }} ({{ $agency->code }})</option>
                        @endforeach
                    </select>
                    @error('agency_id')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="user_id" class="block text-sm font-medium text-slate-700 mb-1.5">User account</label>
                    <select name="user_id" id="user_id" required class="w-full rounded-xl border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
                        <option value="">Select user</option>
                        @foreach ($users as $user)
                            <option value="{{ $user->id }}" data-agency="{{ $user->agency_id }}" @selected(old('user_id') == $user->id)>{{ $user->name }} — {{ $user->email }}</option>
                        @endforeach
                    </select>
                    @error('user_id')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                </div>
            </div>

            <div class="grid sm:grid-cols-2 gap-5">
                <div>
                    <label for="amount" class="block text-sm font-medium text-slate-700 mb-1.5">Amount (BDT)</label>
                    <input type="number" name="amount" id="amount" min="1" step="0.01" value="{{ old('amount') }}" required class="w-full rounded-xl border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
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
            </div>

            <div class="grid sm:grid-cols-2 gap-5">
                <div>
                    <label for="mfs_sender_phone" class="block text-sm font-medium text-slate-700 mb-1.5">Sender mobile number</label>
                    <input type="text" name="mfs_sender_phone" id="mfs_sender_phone" value="{{ old('mfs_sender_phone') }}" required maxlength="32" placeholder="01XXXXXXXXX" class="w-full rounded-xl border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
                    @error('mfs_sender_phone')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="mfs_transaction_id" class="block text-sm font-medium text-slate-700 mb-1.5">Transaction ID</label>
                    <input type="text" name="mfs_transaction_id" id="mfs_transaction_id" value="{{ old('mfs_transaction_id') }}" required maxlength="128" class="w-full rounded-xl border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
                    @error('mfs_transaction_id')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                </div>
            </div>

            <div>
                <label for="receipt" class="block text-sm font-medium text-slate-700 mb-1.5">Payment proof (optional)</label>
                <input type="file" name="receipt" id="receipt" accept="image/*,.pdf" class="w-full rounded-xl border-slate-200 text-sm">
                <p class="text-xs text-slate-400 mt-1">The deposit will remain pending until an admin approves it.</p>
                @error('receipt')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
            </div>

            <button type="submit" class="inline-flex items-center justify-center px-5 py-2.5 rounded-xl bg-gradient-to-r from-indigo-500 to-fuchsia-500 text-white text-sm font-semibold shadow-lg shadow-indigo-500/20 hover:from-indigo-600 hover:to-fuchsia-600 transition">Create Pending Deposit</button>
        </form>
    </div>
</div>

<script>
    const agency = document.getElementById('agency_id');
    const user = document.getElementById('user_id');
    const filterUsers = () => {
        [...user.options].forEach((option, index) => {
            if (index === 0) return;
            option.hidden = Boolean(agency.value) && option.dataset.agency !== agency.value;
            if (option.hidden && option.selected) user.value = '';
        });
    };
    agency.addEventListener('change', filterUsers);
    filterUsers();
</script>
@endsection
