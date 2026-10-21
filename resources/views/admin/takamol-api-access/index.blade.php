@extends('layouts.panel')

@section('title', 'External Takamol API Access')
@section('page-title', 'External Takamol API Access')

@section('content')
<div class="space-y-6">
    @if (session('success'))
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm text-emerald-800">{{ session('success') }}</div>
    @endif
    @if ($errors->any())
        <div class="rounded-2xl border border-red-200 bg-red-50 px-5 py-4 text-sm text-red-800">{{ $errors->first() }}</div>
    @endif

    @if (session('created_takamol_api_key'))
        <section class="rounded-2xl border border-amber-300 bg-amber-50 p-5">
            <h2 class="font-bold text-amber-950">Copy this key now</h2>
            <p class="mt-1 text-sm text-amber-900">It is shown once only. Store it as a server-side secret on the consuming website. Never put it in browser JavaScript, a URL, logs, source control, or chat.</p>
            <code class="mt-3 block select-all break-all rounded-xl bg-white p-4 text-sm text-slate-900">{{ session('created_takamol_api_key') }}</code>
        </section>
    @endif

    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-indigo-600">Read-only shared-session API</p>
        <h2 class="mt-1 text-xl font-bold text-slate-900">Generate a key for another website</h2>
        <p class="mt-2 max-w-4xl text-sm leading-6 text-slate-600">The consumer key authorizes lookup calls through this app's existing server-side agent session. Each key is stored as a SHA-256 hash and does not map to a candidate SVP session or Portal Availability cookie. This API cannot book, reserve, pay, cancel, or change accounts.</p>

        <div class="mt-4 rounded-xl border {{ $credentialsConfigured || ($sessionSummary['stored'] ?? false) ? 'border-emerald-200 bg-emerald-50 text-emerald-900' : 'border-amber-200 bg-amber-50 text-amber-900' }} px-4 py-3 text-sm">
            Takamol agent email/password login configured: <strong>{{ $credentialsConfigured ? 'yes' : 'no' }}</strong>.
            Stored agent session: <strong>{{ ($sessionSummary['stored'] ?? false) ? 'yes' : 'no' }}</strong>.
            Fresh session: <strong>{{ ($sessionSummary['fresh'] ?? false) ? 'yes' : 'no' }}</strong>.
            Session key present: <strong>{{ ($sessionSummary['has_key'] ?? false) ? 'yes' : 'no' }}</strong>.
            The first request may use configured session material or perform server-side login if a refresh is needed.
        </div>

        <form method="POST" action="{{ route('admin.takamol-api-access.store') }}" class="mt-5 grid gap-4 md:grid-cols-2">
            @csrf
            <label class="block text-sm font-medium text-slate-700">
                Key name
                <input name="name" value="{{ old('name') }}" required maxlength="120" autocomplete="off" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm" placeholder="Partner website">
            </label>
            <label class="block text-sm font-medium text-slate-700">
                Key expires at (optional)
                <input type="datetime-local" name="expires_at" value="{{ old('expires_at') }}" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm">
            </label>
            <label class="block text-sm font-medium text-slate-700">
                Requests per minute
                <input type="number" name="rate_limit_per_minute" value="{{ old('rate_limit_per_minute', 60) }}" required min="1" max="1000" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm">
            </label>
            <div class="md:col-span-2">
                <button type="submit" class="rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700">Generate Takamol API key</button>
            </div>
        </form>
    </section>

    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <h2 class="text-lg font-bold text-slate-900">Issued Takamol keys</h2>
        <div class="mt-4 overflow-x-auto">
            <table class="min-w-full text-left text-sm">
                <thead class="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                    <tr><th class="px-3 py-3">Name / key prefix</th><th class="px-3 py-3">Limits / expiry</th><th class="px-3 py-3">Status</th><th class="px-3 py-3">Action</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-slate-700">
                    @forelse ($apiKeys as $apiKey)
                        <tr>
                            <td class="px-3 py-3"><div class="font-semibold">{{ $apiKey->name }}</div><code class="text-xs text-slate-500">{{ $apiKey->key_prefix }}…</code></td>
                            <td class="px-3 py-3">{{ $apiKey->rate_limit_per_minute }}/min<div class="text-xs text-slate-500">{{ $apiKey->expires_at?->toDateTimeString() ?? 'No key expiry' }}</div></td>
                            <td class="px-3 py-3">
                                @if ($apiKey->revoked_at)
                                    <span class="font-semibold text-red-700">Revoked</span>
                                @elseif ($apiKey->expires_at && $apiKey->expires_at->isPast())
                                    <span class="font-semibold text-amber-700">Expired</span>
                                @else
                                    <span class="font-semibold text-emerald-700">Active</span>
                                @endif
                                <div class="text-xs text-slate-500">Last used: {{ $apiKey->last_used_at?->toDateTimeString() ?? 'Never' }}</div>
                            </td>
                            <td class="px-3 py-3">
                                @if (! $apiKey->revoked_at)
                                    <form method="POST" action="{{ route('admin.takamol-api-access.revoke', $apiKey) }}" onsubmit="return confirm('Revoke this Takamol API key?')">
                                        @csrf
                                        <button class="rounded-lg border border-red-200 px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-50">Revoke</button>
                                    </form>
                                @else
                                    <span class="text-xs text-slate-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-3 py-8 text-center text-slate-500">No Takamol API keys have been issued.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
@endsection
