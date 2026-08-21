@extends('layouts.panel')

@section('title', 'SVP Availability Accounts')
@section('page-title', 'Backend SVP Availability Accounts')

@section('content')
<div class="space-y-6">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-sm font-semibold text-brand-600">Read-only availability service</p>
            <h2 class="mt-1 text-2xl font-bold text-slate-900">Backend SVP accounts</h2>
            <p class="mt-1 max-w-3xl text-sm text-slate-500">These accounts are used by the availability dashboard for agencies and portal users. Credentials are stored using Laravel encrypted casts and are never displayed after submission.</p>
        </div>
        <a href="{{ route('svp.availability') }}" class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">Open availability</a>
    </div>

    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)]">
        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h3 class="text-base font-bold text-slate-900">Add account</h3>
            <form method="POST" action="{{ route('admin.svp-availability-accounts.store') }}" class="mt-4 space-y-4">
                @csrf
                <label class="block text-sm font-medium text-slate-700">Account label
                    <input name="name" value="{{ old('name') }}" required maxlength="120" class="mt-1.5 w-full rounded-xl border-slate-300 text-sm focus:border-brand-500 focus:ring-brand-500" placeholder="Primary availability account">
                </label>
                <label class="block text-sm font-medium text-slate-700">SVP email <span class="font-normal text-slate-400">(optional)</span>
                    <input type="email" name="email" value="{{ old('email') }}" maxlength="190" class="mt-1.5 w-full rounded-xl border-slate-300 text-sm focus:border-brand-500 focus:ring-brand-500" placeholder="availability@example.com">
                </label>
                <button class="w-full rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-700">Create account</button>
            </form>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h3 class="text-base font-bold text-slate-900">Account status</h3>
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead class="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-3 py-3">Account</th><th class="px-3 py-3">Token</th><th class="px-3 py-3">Last used</th><th class="px-3 py-3">Status</th><th class="px-3 py-3 text-right">Action</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                    @forelse ($accounts as $account)
                        <tr class="align-top"><td class="px-3 py-4"><div class="font-semibold text-slate-800">{{ $account['name'] }}</div><div class="text-xs text-slate-500">{{ $account['email'] ?: 'No email recorded' }}</div></td><td class="px-3 py-4"><span class="rounded-full px-2 py-1 text-xs font-semibold {{ $account['has_token'] ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' }}">{{ $account['has_token'] ? 'Configured' : 'Missing' }}</span>@if ($account['expires_at'])<div class="mt-1 text-xs text-slate-500">Expires {{ $account['expires_at'] }}</div>@endif</td><td class="px-3 py-4 text-xs text-slate-500">{{ $account['last_used_at'] ?: 'Never' }}@if ($account['last_error'])<div class="mt-1 max-w-xs text-red-600">{{ $account['last_error'] }}</div>@endif</td><td class="px-3 py-4"><span class="rounded-full px-2 py-1 text-xs font-semibold {{ $account['active'] ? 'bg-blue-50 text-blue-700' : 'bg-slate-100 text-slate-500' }}">{{ $account['active'] ? 'Active' : 'Inactive' }}</span></td><td class="px-3 py-4 text-right"><div class="flex min-w-[230px] flex-col items-end gap-2"><form method="POST" action="{{ route('admin.svp-availability-accounts.token', $account['id']) }}" class="w-full space-y-2">@csrf<input type="password" name="access_token" required maxlength="10000" class="w-full rounded-lg border-slate-300 text-xs" placeholder="Paste new access token"><input type="password" name="refresh_token" maxlength="10000" class="w-full rounded-lg border-slate-300 text-xs" placeholder="Refresh token (optional)"><input type="datetime-local" name="token_expires_at" class="w-full rounded-lg border-slate-300 text-xs"><button class="w-full rounded-lg bg-slate-800 px-3 py-2 text-xs font-semibold text-white hover:bg-slate-700">Encrypt &amp; save token</button></form>@if ($account['active'])<form method="POST" action="{{ route('admin.svp-availability-accounts.deactivate', $account['id']) }}">@csrf<button class="text-xs font-semibold text-red-600 hover:text-red-700">Deactivate</button></form>@else<form method="POST" action="{{ route('admin.svp-availability-accounts.activate', $account['id']) }}">@csrf<button class="text-xs font-semibold text-emerald-600 hover:text-emerald-700">Activate</button></form>@endif</div></td></tr>
                    @empty
                        <tr><td colspan="5" class="px-3 py-8 text-center text-sm text-slate-500">No backend accounts configured yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <div class="rounded-2xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900"><strong>Operational note:</strong> seed a token copied from a completed SVP login/OTP flow. The dashboard only performs read-only category, city, and exam-session lookups; booking holds continue using the candidate’s own authenticated SVP session.</div>
</div>
@endsection
