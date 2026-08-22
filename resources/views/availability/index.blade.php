@extends('layouts.panel')

@section('title', 'SVP Availability')
@section('page-title', 'SVP Availability')

@section('content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 py-6 space-y-6">
    <div class="rounded-2xl bg-white border border-slate-200 p-5 shadow-sm">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-widest text-brand-600">Read-only live lookup</p>
                <h2 class="mt-1 text-2xl font-bold text-slate-900">Available centers by date</h2>
                <p class="mt-1 text-sm text-slate-500">Only sessions returned by the authenticated SVP availability endpoint are shown. Every session is re-checked against SVP's authoritative session record — expand "Verify sessions" to see the real test center reported by SVP.</p>
            </div>
            @if ($result['fetched_at'])
                <p class="text-xs text-slate-400">Fetched {{ \Carbon\Carbon::parse($result['fetched_at'])->diffForHumans() }}</p>
            @endif
        </div>

        <form method="GET" action="{{ route('svp.availability') }}" class="mt-5 grid grid-cols-1 md:grid-cols-4 gap-3">
            <label class="text-sm font-medium text-slate-700">Category
                @php
                    $categoryOptions = is_array($categories) && array_is_list($categories)
                        ? $categories
                        : ($categories['data'] ?? $categories['categories'] ?? []);
                @endphp
                <select name="category_id" class="mt-1 w-full rounded-xl border-slate-300" required>
                    <option value="">Select category</option>
                    @foreach ($categoryOptions as $category)
                        @php $id = (string) ($category['id'] ?? $category['category_id'] ?? ''); @endphp
                        @if ($id !== '')<option value="{{ $id }}" @selected($categoryId === $id)>{{ $category['name'] ?? $category['english_name'] ?? $id }}</option>@endif
                    @endforeach
                </select>
            </label>
            <label class="text-sm font-medium text-slate-700">City
                <select name="city" class="mt-1 w-full rounded-xl border-slate-300" required>
                    <option value="">Select city</option>
                    @foreach (($cities['data'] ?? $cities['cities'] ?? []) as $item)
                        @php $name = is_array($item) ? (string) ($item['name'] ?? $item['city'] ?? '') : (string) $item; @endphp
                        @if ($name !== '')<option value="{{ $name }}" @selected(strcasecmp($city, $name) === 0)>{{ $name }}</option>@endif
                    @endforeach
                </select>
            </label>
            <label class="text-sm font-medium text-slate-700">Date (optional)
                <input type="date" name="date" value="{{ $date }}" class="mt-1 w-full rounded-xl border-slate-300">
            </label>
            <button class="self-end rounded-xl bg-brand-600 px-4 py-2.5 font-semibold text-white hover:bg-brand-700">Check availability</button>
        </form>
    </div>

    <div id="availability-results" aria-live="polite">
    @if ($categoryId && $city && count($result['rows']) === 0)
        <div class="rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-amber-800">No currently available sessions were returned for this category, city and date.</div>
    @endif

    @foreach (collect($result['rows'])->groupBy('date') as $examDate => $rows)
        <section class="rounded-2xl bg-white border border-slate-200 overflow-hidden shadow-sm">
            <div class="bg-slate-50 px-5 py-4 border-b border-slate-200">
                <h3 class="text-lg font-bold text-slate-900">{{ \Carbon\Carbon::parse($examDate)->format('d M, Y') }}</h3>
                <p class="text-sm text-slate-500">{{ $city }} · {{ $rows->sum('session_count') }} available session(s)</p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-white text-slate-500"><tr><th class="px-5 py-3 text-left font-semibold">Center Name</th><th class="px-5 py-3 text-left font-semibold">Exam Slot</th><th class="px-5 py-3 text-center font-semibold">Sessions</th><th class="px-5 py-3 text-right font-semibold">Verification</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                    @foreach ($rows as $row)
                        @include('availability.partials.session-verification', ['row' => $row, 'ctxCity' => $city])
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endforeach
    </div>
</div>

@push('scripts')
<script>
(() => {
    const form = document.querySelector('form[action="{{ route('svp.availability') }}"]');
    const results = document.getElementById('availability-results');
    const category = form?.querySelector('[name="category_id"]');
    const city = form?.querySelector('[name="city"]');
    const date = form?.querySelector('[name="date"]');
    const button = form?.querySelector('button');
    if (!form || !results || !category || !city || !date) return;

    let timer;
    let cityController;
    let availabilityController;
    let cityRequestId = 0;
    let availabilityRequestId = 0;
    const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));
    const cityName = item => typeof item === 'object' ? (item.name ?? item.city ?? '') : item;
    const categoryId = item => typeof item === 'object' ? (item.id ?? item.category_id ?? '') : item;

    function cancelAvailability() {
        availabilityRequestId += 1;
        availabilityController?.abort();
        availabilityController = null;
    }

    function chip(text) {
        return `<span class="text-[11px] rounded-full bg-slate-100 border border-slate-200 px-2 py-0.5 text-slate-600">${esc(text)}</span>`;
    }

    function sessionCard(row, session, examDate) {
        const real = session.real_test_center || {};
        const isExact = real.match === 'exact';
        const sid = String(session.id || '');
        const shortId = sid.length > 20 ? sid.slice(0, 12) + '…' + sid.slice(-6) : sid;
        const shiftLabel = session.shift_label || session.shift || 'Session';
        const realLabel = real.name || (real.id ? 'Test center ID ' + real.id : 'Not published by SVP');
        const badges = [
            isExact ? '<span class="text-[11px] font-bold rounded-full px-2 py-0.5 bg-emerald-50 text-emerald-700 border border-emerald-200">&#10003; Verified</span>'
                    : '<span class="text-[11px] font-bold rounded-full px-2 py-0.5 bg-amber-50 text-amber-700 border border-amber-200">&#10003; Verified</span>',
            `<span class="inline-flex items-center rounded-lg bg-brand-50 border border-brand-200 px-2 py-0.5 text-[11px] font-bold text-brand-700">${esc(shiftLabel)}</span>`,
            `<span class="text-xs font-semibold text-slate-800" title="${esc(session.shift || '')}">${esc(row.center_name)}</span>`,
            `<code class="text-[10px] text-slate-400" title="${esc(sid)}">${esc(shortId)}</code>`,
            session.status ? chip(session.status) : '',
            session.time_zone_name ? chip(session.time_zone_name) : '',
        ].filter(Boolean).join(' ');
        return `
            <div class="rounded-xl border p-3 ${isExact ? 'border-emerald-200' : 'border-amber-200'}">
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5">${badges}</div>
                <dl class="mt-2 grid grid-cols-2 sm:grid-cols-4 gap-x-4 gap-y-2 text-[11px]">
                    <div><dt class="uppercase tracking-wide text-slate-400">Test centre (listed under)</dt><dd class="font-semibold text-slate-700">${esc(row.center_name)}</dd></div>
                    <div><dt class="uppercase tracking-wide text-slate-400">Real center per SVP</dt><dd class="font-semibold text-slate-700">${esc(realLabel)}</dd></div>
                    <div><dt class="uppercase tracking-wide text-slate-400">City / match type</dt><dd class="font-semibold ${isExact ? 'text-emerald-700' : 'text-amber-700'}">${esc(real.city || city.value)} · ${isExact ? 'Exact' : 'City scope'}</dd></div>
                    <div><dt class="uppercase tracking-wide text-slate-400">Session date (SVP)</dt><dd class="font-semibold text-slate-700">${esc(new Date(examDate + 'T00:00:00').toLocaleDateString(undefined, {day:'2-digit', month:'short', year:'numeric'}))}</dd></div>
                </dl>
                ${isExact ? '' : '<p class="mt-2 text-[11px] text-amber-700">SVP\'s authoritative session record confirms the city but omits the test-center id/name for this deployment; the session is listed under ' + esc(row.center_name) + ', the centre it was queried against.</p>'}
            </div>`;
    }

    function renderRows(data) {
        const rows = data?.rows ?? [];
        if (!rows.length) {
            results.innerHTML = '<div class="rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-amber-800">No currently available sessions were returned for this category, city and date.</div>';
            return;
        }
        const grouped = rows.reduce((acc, row) => ((acc[row.date] ??= []).push(row), acc), {});
        results.innerHTML = Object.entries(grouped).map(([examDate, items]) => `
            <section class="rounded-2xl bg-white border border-slate-200 overflow-hidden shadow-sm mb-6">
                <div class="bg-slate-50 px-5 py-4 border-b border-slate-200">
                    <h3 class="text-lg font-bold text-slate-900">${esc(new Date(examDate + 'T00:00:00').toLocaleDateString(undefined, {day:'2-digit', month:'short', year:'numeric'}))}</h3>
                    <p class="text-sm text-slate-500">${esc(city.value)} · ${items.reduce((sum, row) => sum + Number(row.session_count || 0), 0)} available session(s)</p>
                </div>
                <div class="overflow-x-auto"><table class="min-w-full text-sm"><thead class="bg-white text-slate-500"><tr><th class="px-5 py-3 text-left font-semibold">Center Name</th><th class="px-5 py-3 text-left font-semibold">Exam Slot</th><th class="px-5 py-3 text-center font-semibold">Sessions</th><th class="px-5 py-3 text-right font-semibold">Verification</th></tr></thead><tbody class="divide-y divide-slate-100">
                ${items.map(row => {
                    const sessions = row.sessions || [];
                    const exactCount = sessions.filter(s => s?.real_test_center?.match === 'exact').length;
                    return `
                    <tr>
                        <td class="px-5 py-3 font-medium text-slate-800">${esc(row.center_name)}</td>
                        <td class="px-5 py-3"><span class="font-semibold text-emerald-600">Available</span></td>
                        <td class="px-5 py-3 text-center font-semibold text-slate-700">${esc(row.session_count)}</td>
                        <td class="px-5 py-3 text-right">
                            <button type="button" data-verify-toggle class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-brand-700 hover:border-brand-300 hover:bg-brand-50 transition">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                Verify ${sessions.length} session${sessions.length === 1 ? '' : 's'}
                            </button>
                        </td>
                    </tr>
                    <tr data-verify-panel class="hidden"><td colspan="4" class="bg-slate-50/70 px-5 pb-4">
                        <div class="rounded-xl border border-slate-200 bg-white p-4">
                            <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                                <p class="text-xs font-semibold uppercase tracking-widest text-slate-500">Per-session real test center — checked live against SVP</p>
                                <p class="text-[11px] text-slate-400">${exactCount} exact center · ${sessions.length - exactCount} city scope</p>
                            </div>
                            <p class="mb-3 text-[11px] text-slate-400">Each entry is a separate bookable shift returned live by SVP for <span class="font-semibold">${esc(row.center_name)}</span> on this date — session IDs are unique and never shared between centers.</p>
                            <div class="space-y-2">${sessions.map(s => sessionCard(row, s, examDate)).join('') || '<p class="text-xs text-slate-500">No verified sessions to display.</p>'}</div>
                        </div>
                    </td></tr>`;
                }).join('')}
                </tbody></table></div>
            </section>`).join('');
    }

    results.addEventListener('click', event => {
        const toggle = event.target.closest('[data-verify-toggle]');
        if (!toggle) return;
        const panel = toggle.closest('tr')?.nextElementSibling;
        if (panel?.hasAttribute('data-verify-panel')) panel.classList.toggle('hidden');
    });

    async function loadCities() {
        if (!category.value) {
            city.disabled = true;
            city.innerHTML = '<option value="">Select category first</option>';
            return;
        }

        const requestId = ++cityRequestId;
        cityController?.abort();
        const requestController = new AbortController();
        cityController = requestController;
        let timedOut = false;
        const timeoutId = setTimeout(() => {
            timedOut = true;
            requestController.abort();
        }, 15000);
        city.disabled = true;
        city.innerHTML = '<option value="">Loading cities…</option>';
        results.innerHTML = '<div class="rounded-2xl border border-slate-200 bg-slate-50 px-5 py-4 text-slate-600">Select a city to check availability.</div>';

        try {
            const params = new URLSearchParams({category_id: category.value});
            const response = await fetch(`{{ route('svp.availability.cities') }}?${params}`, {headers: {Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest'}, signal: requestController.signal});
            const payload = await response.json();
            if (requestId !== cityRequestId) return;
            if (!response.ok || payload.success !== true) throw new Error(payload.message || 'City lookup failed.');
            const cities = payload.data ?? [];
            city.innerHTML = '<option value="">Select city</option>' + cities.map(item => { const value = cityName(item); return `<option value="${esc(value)}">${esc(value)}</option>`; }).join('');
            city.disabled = cities.length === 0;
            if (!cities.length) results.innerHTML = '<div class="rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-amber-800">No cities are currently available for this category.</div>';
        } catch (error) {
            if (requestId !== cityRequestId || (error.name === 'AbortError' && !timedOut)) return;
            city.innerHTML = '<option value="">City lookup unavailable</option>';
            city.disabled = true;
            const message = timedOut ? 'City lookup timed out. Please try again.' : (error.message || 'City lookup failed.');
            results.innerHTML = `<div class="rounded-2xl border border-red-200 bg-red-50 px-5 py-4 text-red-800">${esc(message)}</div>`;
        } finally {
            clearTimeout(timeoutId);
            if (cityController === requestController) cityController = null;
        }
    }

    async function refresh() {
        if (!category.value || !city.value) {
            results.innerHTML = '<div class="rounded-2xl border border-slate-200 bg-slate-50 px-5 py-4 text-slate-600">Select a category and city to check availability.</div>';
            return;
        }
        cancelAvailability();
        const requestId = availabilityRequestId;
        const requestController = new AbortController();
        availabilityController = requestController;
        let timedOut = false;
        const timeoutId = setTimeout(() => {
            timedOut = true;
            requestController.abort();
        }, 30000);
        button.disabled = true;
        button.textContent = 'Loading…';
        results.innerHTML = '<div class="rounded-2xl border border-blue-200 bg-blue-50 px-5 py-4 text-blue-800">Loading available centers…</div>';
        const params = new URLSearchParams({category_id: category.value, city: city.value});
        if (date.value) params.set('date', date.value);
        try {
            const response = await fetch(`${form.action}?${params}`, {headers: {Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest'}, signal: requestController.signal});
            const payload = await response.json();
            if (requestId !== availabilityRequestId) return;
            if (!response.ok || payload.success !== true) throw new Error(payload.message || 'Availability request failed.');
            renderRows(payload.data ?? payload);
        } catch (error) {
            if (requestId !== availabilityRequestId || (error.name === 'AbortError' && !timedOut)) return;
            const message = timedOut ? 'Availability lookup timed out. Please try again.' : (error.message || 'Availability request failed.');
            results.innerHTML = `<div class="rounded-2xl border border-red-200 bg-red-50 px-5 py-4 text-red-800">${esc(message)}</div>`;
        } finally {
            clearTimeout(timeoutId);
            if (requestId === availabilityRequestId) {
                button.disabled = false;
                button.textContent = 'Check availability';
            }
            if (availabilityController === requestController) availabilityController = null;
        }
    }

    function schedule() {
        clearTimeout(timer);
        cancelAvailability();
        timer = setTimeout(refresh, 350);
    }
    category.addEventListener('change', () => { cancelAvailability(); city.value = ''; loadCities(); });
    city.addEventListener('change', schedule);
    date.addEventListener('change', schedule);
    form.addEventListener('submit', event => { event.preventDefault(); clearTimeout(timer); refresh(); });
})();
</script>
@endpush
@endsection
