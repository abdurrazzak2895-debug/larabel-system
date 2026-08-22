@extends('layouts.panel')

@section('title', 'Portal Availability')
@section('page-title', 'Portal Availability')

@section('content')
<div class="space-y-6" id="portal-availability-app"
     data-urls='@json([
        "occupations" => route("admin.portal-availability.occupations"),
        "dates" => route("admin.portal-availability.dates"),
        "centers" => route("admin.portal-availability.centers"),
     ])'
     data-csrf="{{ csrf_token() }}">
    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-600">Read-only portal adapter</p>
                <h2 class="mt-1 text-2xl font-bold text-slate-900">Live occupations, dates and centers</h2>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-500">This dashboard calls only the authorized portal availability endpoints. It displays live availability and lets you select a center locally; it never creates a booking, hold, reservation, payment, OTP, or account change.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <label class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-700">
                    <input id="portal-auto-refresh" type="checkbox" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                    Auto-refresh 60s
                </label>
                <button type="button" id="portal-refresh" class="rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-700">Refresh now</button>
            </div>
        </div>
    </section>

    @if (session('success'))
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm text-emerald-800">{{ session('success') }}</div>
    @endif
    @if ($errors->any())
        <div class="rounded-2xl border border-red-200 bg-red-50 px-5 py-4 text-sm text-red-800">{{ $errors->first() }}</div>
    @endif

    <section class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(0,1.35fr)]">
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h3 class="text-base font-bold text-slate-900">Portal session setup</h3>
                    <p class="mt-1 text-xs leading-5 text-slate-500">Session cookies are encrypted at rest and never returned to the browser after saving.</p>
                </div>
                <span class="rounded-full bg-amber-50 px-2.5 py-1 text-[11px] font-bold text-amber-700">Authorized use only</span>
            </div>
            <form method="POST" action="{{ route('admin.portal-availability.credentials.store') }}" class="mt-5 space-y-3">
                @csrf
                <label class="block text-sm font-medium text-slate-700">Credential label
                    <input name="name" required maxlength="120" value="{{ old('name') }}" class="mt-1.5 w-full rounded-xl border-slate-300 text-sm" placeholder="Primary portal session">
                </label>
                <label class="block text-sm font-medium text-slate-700">Portal account ID
                    <input name="portal_account_id" required maxlength="120" value="{{ old('portal_account_id') }}" class="mt-1.5 w-full rounded-xl border-slate-300 text-sm" placeholder="Account ID used by search_dates/centers">
                </label>
                <label class="block text-sm font-medium text-slate-700">Authorized session cookie
                    <textarea name="session_cookie" required maxlength="10000" rows="3" class="mt-1.5 w-full rounded-xl border-slate-300 font-mono text-xs" placeholder="Paste the full Cookie header only when authorized"></textarea>
                </label>
                <label class="block text-sm font-medium text-slate-700">Cookie expiry (optional)
                    <input type="datetime-local" name="expires_at" class="mt-1.5 w-full rounded-xl border-slate-300 text-sm">
                </label>
                <button class="w-full rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800">Encrypt &amp; save session</button>
            </form>
            <div class="mt-4 rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-xs leading-5 text-blue-900">Use a supported portal service credential when available. Do not store passwords, OTPs, or cookies in JavaScript, GitHub, logs, or screenshots.</div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h3 class="text-base font-bold text-slate-900">Saved portal sessions</h3>
                    <p class="mt-1 text-xs text-slate-500">Only active, non-expired sessions can be used by the live lookup.</p>
                </div>
                <span class="rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-bold text-slate-600">{{ count($credentials) }} configured</span>
            </div>
            <div class="mt-4 space-y-3">
                @forelse ($credentials as $credential)
                    <article class="rounded-xl border {{ $credential['usable'] ? 'border-emerald-200 bg-emerald-50/40' : 'border-slate-200 bg-slate-50' }} p-4">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <div class="flex flex-wrap items-center gap-2"><h4 class="font-bold text-slate-900">{{ $credential['name'] }}</h4><span class="rounded-full px-2 py-0.5 text-[11px] font-bold {{ $credential['usable'] ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-600' }}">{{ $credential['usable'] ? 'Ready' : 'Unavailable' }}</span></div>
                                <p class="mt-1 text-xs text-slate-500">Account ID: {{ $credential['portal_account_id'] }}</p>
                                @if ($credential['expires_at'])<p class="mt-1 text-xs text-slate-500">Expires: {{ $credential['expires_at'] }}</p>@endif
                                @if ($credential['last_checked_at'])<p class="mt-1 text-xs text-slate-500">Last checked: {{ $credential['last_checked_at'] }}</p>@endif
                                @if ($credential['last_error'])<p class="mt-2 text-xs text-red-700">{{ $credential['last_error'] }}</p>@endif
                            </div>
                            <div class="flex shrink-0 gap-2">
                                @if ($credential['active'])
                                    <form method="POST" action="{{ route('admin.portal-availability.credentials.deactivate', $credential['id']) }}">@csrf<button class="rounded-lg border border-red-200 px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-50">Deactivate</button></form>
                                @else
                                    <form method="POST" action="{{ route('admin.portal-availability.credentials.activate', $credential['id']) }}">@csrf<button class="rounded-lg border border-emerald-200 px-3 py-1.5 text-xs font-semibold text-emerald-700 hover:bg-emerald-50">Activate</button></form>
                                @endif
                                <details class="mt-2">
                                    <summary class="cursor-pointer text-xs font-semibold text-brand-700">Edit / rotate</summary>
                                    <form method="POST" action="{{ route('admin.portal-availability.credentials.update', $credential['id']) }}" class="mt-3 w-72 space-y-2 rounded-lg border border-slate-200 bg-white p-3">
                                        @csrf
                                        @method('PUT')
                                        <input name="name" required maxlength="120" value="{{ $credential['name'] }}" class="w-full rounded-lg border-slate-300 text-xs" aria-label="Credential label">
                                        <input name="portal_account_id" required maxlength="120" value="{{ $credential['portal_account_id'] }}" class="w-full rounded-lg border-slate-300 text-xs" aria-label="Portal account ID">
                                        <input name="expires_at" type="datetime-local" value="{{ $credential['expires_at'] ? \Illuminate\Support\Carbon::parse($credential['expires_at'])->format('Y-m-d\\TH:i') : '' }}" class="w-full rounded-lg border-slate-300 text-xs" aria-label="Cookie expiry">
                                        <textarea name="session_cookie" maxlength="10000" rows="2" autocomplete="off" class="w-full rounded-lg border-slate-300 font-mono text-[11px]" placeholder="Paste a replacement Cookie header only if rotating"></textarea>
                                        <button class="w-full rounded-lg bg-brand-600 px-3 py-2 text-xs font-semibold text-white hover:bg-brand-700">Save changes</button>
                                    </form>
                                </details>
                            </div>
                        </div>
                    </article>
                @empty
                    <div class="rounded-xl border border-dashed border-slate-300 px-4 py-8 text-center text-sm text-slate-500">No portal sessions configured yet.</div>
                @endforelse
            </div>
        </div>
    </section>

    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
            <label class="text-sm font-medium text-slate-700">Session credential
                <select id="portal-credential" class="mt-1.5 w-full rounded-xl border-slate-300 text-sm" {{ count($credentials) ? '' : 'disabled' }}>
                    <option value="">Select a ready session</option>
                    @foreach ($credentials as $credential)
                        @if ($credential['usable'])<option value="{{ $credential['id'] }}">{{ $credential['name'] }} · {{ $credential['portal_account_id'] }}</option>@endif
                    @endforeach
                </select>
            </label>
            <label class="text-sm font-medium text-slate-700 md:col-span-2">Occupation
                <select id="portal-occupation" class="mt-1.5 w-full rounded-xl border-slate-300 text-sm" disabled><option value="">Loading occupations…</option></select>
            </label>
            <label class="text-sm font-medium text-slate-700">Start date
                <input id="portal-start-date" type="date" value="{{ now()->format('Y-m-d') }}" class="mt-1.5 w-full rounded-xl border-slate-300 text-sm">
            </label>
        </div>
        <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-4">
            <label class="text-sm font-medium text-slate-700">Language
                <select id="portal-language" class="mt-1.5 w-full rounded-xl border-slate-300 text-sm" disabled><option value="">Select occupation first</option></select>
            </label>
            <div class="flex items-end md:col-span-3"><button type="button" id="portal-search-dates" class="w-full rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-700" disabled>Search available dates</button></div>
        </div>
        <div id="portal-status" class="mt-4 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600" aria-live="polite">Select a ready credential to load live occupations.</div>
    </section>

    <section id="portal-dates-panel" class="hidden rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between"><div><h3 class="text-lg font-bold text-slate-900">Available dates</h3><p id="portal-dates-meta" class="mt-1 text-xs text-slate-500"></p></div><div id="portal-districts" class="flex flex-wrap gap-2"></div></div>
        <div id="portal-dates" class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4"></div>
    </section>

    <section id="portal-centers-panel" class="hidden rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between"><div><h3 class="text-lg font-bold text-slate-900">Available test centers</h3><p id="portal-centers-meta" class="mt-1 text-xs text-slate-500"></p></div><span id="portal-selected-center" class="hidden rounded-full bg-brand-50 px-3 py-1 text-xs font-bold text-brand-700"></span></div>
        <div id="portal-centers" class="mt-5 grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3"></div>
    </section>
</div>
@endsection

@push('scripts')
<script>
(() => {
    const app = document.getElementById('portal-availability-app');
    if (!app) return;
    const urls = JSON.parse(app.dataset.urls || '{}');
    const csrf = app.dataset.csrf;
    const credential = document.getElementById('portal-credential');
    const occupation = document.getElementById('portal-occupation');
    const language = document.getElementById('portal-language');
    const startDate = document.getElementById('portal-start-date');
    const searchDatesButton = document.getElementById('portal-search-dates');
    const refreshButton = document.getElementById('portal-refresh');
    const autoRefresh = document.getElementById('portal-auto-refresh');
    const status = document.getElementById('portal-status');
    const datesPanel = document.getElementById('portal-dates-panel');
    const datesMeta = document.getElementById('portal-dates-meta');
    const districts = document.getElementById('portal-districts');
    const datesWrap = document.getElementById('portal-dates');
    const centersPanel = document.getElementById('portal-centers-panel');
    const centersMeta = document.getElementById('portal-centers-meta');
    const centersWrap = document.getElementById('portal-centers');
    const selectedCenter = document.getElementById('portal-selected-center');
    const esc = value => String(value ?? '').replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[char]));
    let occupations = [];
    let dateRows = [];
    let currentRequest = null;
    let selectedDate = null;
    let activeDistrict = '';
    let refreshTimer = null;

    function say(message, kind = 'info') {
        const colors = {info: 'border-slate-200 bg-slate-50 text-slate-600', success: 'border-emerald-200 bg-emerald-50 text-emerald-800', error: 'border-red-200 bg-red-50 text-red-800'};
        status.className = `mt-4 rounded-xl border px-4 py-3 text-sm ${colors[kind] || colors.info}`;
        status.textContent = message;
    }

    async function request(url, options = {}) {
        currentRequest?.abort();
        currentRequest = new AbortController();
        const response = await fetch(url, {
            ...options,
            credentials: 'same-origin',
            headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf, ...(options.headers || {})},
            signal: currentRequest.signal,
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok || payload.success !== true) throw new Error(payload.message || 'Portal request failed.');
        return payload.data;
    }

    function selectedOccupation() { return occupations.find(item => String(item.occupation_id) === String(occupation.value)); }

    function renderLanguages() {
        const item = selectedOccupation();
        language.innerHTML = item ? '<option value="">Select language</option>' + (item.languages || []).map(lang => `<option value="${esc(lang.code)}">${esc(lang.name || lang.code)} (${esc(lang.code)})</option>`).join('') : '<option value="">Select occupation first</option>';
        language.disabled = !item;
        searchDatesButton.disabled = !(credential.value && item && language.value);
    }

    function renderOccupations() {
        occupation.innerHTML = '<option value="">Select occupation</option>' + occupations.map(item => `<option value="${esc(item.occupation_id)}">${esc(item.name)} · occupation ${esc(item.occupation_id)} · category ${esc(item.category_id)}</option>`).join('');
        occupation.disabled = occupations.length === 0;
    }

    async function loadOccupations() {
        if (!credential.value) {
            occupation.innerHTML = '<option value="">Select a ready session first</option>';
            occupation.disabled = true; language.disabled = true; searchDatesButton.disabled = true;
            say('Select a ready credential to load live occupations.');
            return;
        }
        say('Loading live occupations…');
        try {
            const result = await request(`${urls.occupations}?credential_id=${encodeURIComponent(credential.value)}`);
            occupations = result.data || [];
            renderOccupations();
            language.innerHTML = '<option value="">Select occupation first</option>';
            language.disabled = true;
            searchDatesButton.disabled = true;
            say(`${occupations.length} live occupations loaded. Select an occupation and language.`, 'success');
        } catch (error) {
            if (error.name === 'AbortError') return;
            say(error.message, 'error');
        }
    }

    function renderDateRows() {
        const visible = activeDistrict ? dateRows.filter(item => item.city === activeDistrict) : dateRows;
        datesWrap.innerHTML = visible.length ? visible.map(item => `<button type="button" class="date-card rounded-xl border border-slate-200 bg-slate-50 p-4 text-left transition hover:border-brand-400 hover:bg-brand-50" data-city="${esc(item.city)}" data-date="${esc(item.date)}"><span class="text-xs font-bold uppercase tracking-wider text-brand-600">${esc(new Date(item.date + 'T00:00:00').toLocaleDateString(undefined, {weekday:'short'}))}</span><strong class="mt-1 block text-base text-slate-900">${esc(new Date(item.date + 'T00:00:00').toLocaleDateString(undefined, {day:'2-digit', month:'short', year:'numeric'}))}</strong><span class="mt-1 block text-xs text-slate-500">${esc(item.city)}</span></button>`).join('') : '<div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-5 text-sm text-amber-800 sm:col-span-2 lg:col-span-4">No available dates returned for this selection.</div>';
        datesWrap.querySelectorAll('.date-card').forEach(card => card.addEventListener('click', () => loadCenters(card.dataset.city, card.dataset.date)));
    }

    function renderDistricts(counts) {
        const entries = Object.entries(counts || {});
        districts.innerHTML = `<button type="button" class="district-filter rounded-full bg-brand-600 px-3 py-1.5 text-xs font-bold text-white" data-city="">All districts (${dateRows.length})</button>` + entries.map(([city, count]) => `<button type="button" class="district-filter rounded-full border border-slate-200 bg-white px-3 py-1.5 text-xs font-bold text-slate-700 hover:border-brand-300" data-city="${esc(city)}">${esc(city)} (${esc(count)})</button>`).join('');
        districts.querySelectorAll('.district-filter').forEach(button => button.addEventListener('click', () => { activeDistrict = button.dataset.city; renderDateRows(); }));
    }

    async function loadDates() {
        const item = selectedOccupation();
        if (!credential.value || !item || !startDate.value) return;
        say('Loading live available dates…');
        searchDatesButton.disabled = true;
        try {
            const result = await request(urls.dates, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({credential_id: Number(credential.value), category_id: item.category_id, start_from: startDate.value})});
            dateRows = result.data?.dates || [];
            selectedDate = null; activeDistrict = '';
            datesPanel.classList.remove('hidden'); centersPanel.classList.add('hidden');
            datesMeta.textContent = `${dateRows.length} dates returned · fetched ${result.fetched_at || 'now'}`;
            renderDistricts(result.data?.district_counts || {}); renderDateRows();
            say(`${dateRows.length} available dates loaded. Select a date to load its centers.`, 'success');
        } catch (error) {
            if (error.name === 'AbortError') return;
            say(error.message, 'error');
        } finally { searchDatesButton.disabled = false; }
    }

    async function loadCenters(city, date) {
        const item = selectedOccupation();
        if (!credential.value || !item || !language.value) return;
        selectedDate = {city, date};
        centersPanel.classList.remove('hidden');
        centersMeta.textContent = `${city} · ${date} · loading live centers…`;
        centersWrap.innerHTML = '<div class="rounded-xl border border-blue-200 bg-blue-50 px-4 py-5 text-sm text-blue-800 md:col-span-2 xl:col-span-3">Loading live centers…</div>';
        selectedCenter.classList.add('hidden');
        try {
            const result = await request(urls.centers, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({credential_id: Number(credential.value), category_id: item.category_id, city, date, occupation_id: Number(item.occupation_id), language_code: language.value})});
            const rows = result.data?.centers || [];
            centersMeta.textContent = `${city} · ${date} · ${rows.length} center slot${rows.length === 1 ? '' : 's'} · fetched ${result.fetched_at || 'now'}`;
            centersWrap.innerHTML = rows.length ? rows.map((center, index) => `<button type="button" class="center-card rounded-xl border border-slate-200 bg-white p-4 text-left shadow-sm transition hover:border-brand-400 hover:bg-brand-50" data-index="${index}"><div class="flex items-start justify-between gap-3"><strong class="text-sm text-slate-900">${esc(center.test_center_name)}</strong><span class="rounded-full ${Number(center.available_seats) <= 3 ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700'} px-2 py-1 text-[11px] font-bold">${esc(center.available_seats)} seats</span></div><div class="mt-3 grid grid-cols-2 gap-3 text-xs text-slate-500"><span>Center ID<br><b class="text-slate-800">${esc(center.test_center_id)}</b></span><span>Test time<br><b class="text-slate-800">${esc(center.test_time || 'Not provided')}</b></span></div></button>`).join('') : '<div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-5 text-sm text-amber-800 md:col-span-2 xl:col-span-3">No centers available for this date and city.</div>';
            centersWrap.querySelectorAll('.center-card').forEach(card => card.addEventListener('click', () => { const center = rows[Number(card.dataset.index)]; selectedCenter.textContent = `Selected locally: ${center.test_center_name}`; selectedCenter.classList.remove('hidden'); centersWrap.querySelectorAll('.center-card').forEach(other => other.classList.remove('ring-2', 'ring-brand-500')); card.classList.add('ring-2', 'ring-brand-500'); }));
        } catch (error) {
            if (error.name === 'AbortError') return;
            centersWrap.innerHTML = `<div class="rounded-xl border border-red-200 bg-red-50 px-4 py-5 text-sm text-red-800 md:col-span-2 xl:col-span-3">${esc(error.message)}</div>`;
            centersMeta.textContent = `${city} · ${date}`;
        }
    }

    async function refreshAll() {
        if (!credential.value || !selectedOccupation() || !language.value) return loadOccupations();
        await loadDates();
        if (selectedDate) await loadCenters(selectedDate.city, selectedDate.date);
    }

    credential.addEventListener('change', loadOccupations);
    occupation.addEventListener('change', () => { renderLanguages(); centersPanel.classList.add('hidden'); datesPanel.classList.add('hidden'); });
    language.addEventListener('change', renderLanguages);
    searchDatesButton.addEventListener('click', loadDates);
    refreshButton.addEventListener('click', refreshAll);
    autoRefresh.addEventListener('change', () => { clearInterval(refreshTimer); refreshTimer = autoRefresh.checked ? setInterval(refreshAll, 60000) : null; });
    loadOccupations();
})();
</script>
@endpush
