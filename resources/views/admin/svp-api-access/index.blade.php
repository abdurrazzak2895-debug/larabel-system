@extends('layouts.panel')

@section('title', 'External SVP API Access')
@section('page-title', 'External SVP API Access')

@section('content')
<div class="space-y-6">
    @if (session('success'))
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm text-emerald-800">{{ session('success') }}</div>
    @endif
    @if ($errors->any())
        <div class="rounded-2xl border border-red-200 bg-red-50 px-5 py-4 text-sm text-red-800">{{ $errors->first() }}</div>
    @endif

    @if (session('created_svp_api_key'))
        <section class="rounded-2xl border border-amber-300 bg-amber-50 p-5">
            <h2 class="font-bold text-amber-950">Copy this key now</h2>
            <p class="mt-1 text-sm text-amber-900">It is shown once only. Store it as a server-side secret on the consuming website; never put it in browser JavaScript or send it in chat.</p>
            <code class="mt-3 block select-all break-all rounded-xl bg-white p-4 text-sm text-slate-900">{{ session('created_svp_api_key') }}</code>
        </section>
    @endif

    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-indigo-600">One-domain consumer API</p>
        <h2 class="mt-1 text-xl font-bold text-slate-900">Generate a key for another website</h2>
        <p class="mt-2 max-w-4xl text-sm leading-6 text-slate-600">The other website calls this app at <strong>choice-pc-sv.xyz</strong> with the generated API key. The key maps to an SVP/Takamol bearer session already saved and encrypted by this app. The bearer token is never returned to the caller. This gateway exposes read-only lookup routes; it cannot book, hold seats, pay, cancel, or change accounts.</p>

        @php($usableSessions = $sessions->filter(fn ($savedSession) => $savedSession->usable()))
        @if ($usableSessions->isEmpty())
            <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">No usable saved SVP session is available. Sign in through the existing SVP login flow for a candidate first; this page does not ask for or store a bearer token.</div>
        @endif

        <form method="POST" action="{{ route('admin.svp-api-access.store') }}" class="mt-5 grid gap-4 md:grid-cols-2">
            @csrf
            <label class="block text-sm font-medium text-slate-700">
                Key name
                <input name="name" value="{{ old('name') }}" required maxlength="120" autocomplete="off" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm" placeholder="Partner website">
            </label>
            <label class="block text-sm font-medium text-slate-700">
                Saved SVP session
                <select name="candidate_svp_session_id" required class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm" @disabled($usableSessions->isEmpty())>
                    <option value="">Select a usable session</option>
                    @foreach ($sessions as $savedSession)
                        @php($candidate = $savedSession->candidate)
                        <option value="{{ $savedSession->id }}" @selected((string) old('candidate_svp_session_id') === (string) $savedSession->id) @disabled(! $savedSession->usable())>
                            {{ $candidate?->full_name ?? 'Candidate' }} — candidate #{{ $savedSession->candidate_id }}
                            @if ($savedSession->svp_user_id) (SVP {{ $savedSession->svp_user_id }}) @endif
                            @if (! $savedSession->usable()) — expired/unavailable @endif
                        </option>
                    @endforeach
                </select>
                <span class="mt-1 block text-xs text-slate-500">Usable means the encrypted token exists and has not expired according to its saved expiry.</span>
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
                <button type="submit" @disabled($usableSessions->isEmpty()) class="rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50">Generate API key</button>
            </div>
        </form>
    </section>

    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <h2 class="text-lg font-bold text-slate-900">Issued keys</h2>
        <div class="mt-4 overflow-x-auto">
            <table class="min-w-full text-left text-sm">
                <thead class="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                    <tr><th class="px-3 py-3">Name / key prefix</th><th class="px-3 py-3">Mapped SVP session</th><th class="px-3 py-3">Limits / expiry</th><th class="px-3 py-3">Status</th><th class="px-3 py-3">Action</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-slate-700">
                    @forelse ($apiKeys as $apiKey)
                        @php($candidate = $apiKey->session?->candidate)
                        <tr>
                            <td class="px-3 py-3"><div class="font-semibold">{{ $apiKey->name }}</div><code class="text-xs text-slate-500">{{ $apiKey->key_prefix }}…</code></td>
                            <td class="px-3 py-3">{{ $candidate?->full_name ?? 'Deleted candidate' }}<div class="text-xs text-slate-500">Candidate #{{ $apiKey->candidate_svp_session_id }}</div></td>
                            <td class="px-3 py-3">{{ $apiKey->rate_limit_per_minute }}/min<div class="text-xs text-slate-500">{{ $apiKey->expires_at?->toDateTimeString() ?? 'No key expiry' }}</div></td>
                            <td class="px-3 py-3">
                                @if ($apiKey->revoked_at)
                                    <span class="font-semibold text-red-700">Revoked</span>
                                @elseif ($apiKey->expires_at && $apiKey->expires_at->isPast())
                                    <span class="font-semibold text-amber-700">Expired</span>
                                @elseif (! $apiKey->session?->usable())
                                    <span class="font-semibold text-amber-700">SVP session expired</span>
                                @else
                                    <span class="font-semibold text-emerald-700">Active</span>
                                @endif
                                <div class="text-xs text-slate-500">Last used: {{ $apiKey->last_used_at?->toDateTimeString() ?? 'Never' }}</div>
                            </td>
                            <td class="px-3 py-3">
                                @if (! $apiKey->revoked_at)
                                    <form method="POST" action="{{ route('admin.svp-api-access.revoke', $apiKey) }}" onsubmit="return confirm('Revoke this API key?')">
                                        @csrf
                                        <button class="rounded-lg border border-red-200 px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-50">Revoke</button>
                                    </form>
                                @else
                                    <span class="text-xs text-slate-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-3 py-8 text-center text-slate-500">No SVP API keys have been issued.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
@endsection
