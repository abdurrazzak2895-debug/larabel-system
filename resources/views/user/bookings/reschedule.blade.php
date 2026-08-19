@extends('layouts.user')

@section('title', 'Reschedule SVP Reservation')
@section('page-title', 'Reschedule SVP Reservation')

@php
    $candidateName = data_get($reservationData, 'full_name')
        ?? data_get($reservationData, 'fullName')
        ?? data_get($reservationData, 'candidate.full_name')
        ?? data_get($reservationData, 'user.full_name')
        ?? 'SVP candidate';
    $occupationName = data_get($reservationData, 'occupation.name')
        ?? data_get($reservationData, 'occupation.english_name')
        ?? data_get($reservationData, 'occupation.name_en')
        ?? data_get($reservationData, 'occupation')
        ?? 'Occupation '.$context['occupation_id'];
    $categoryName = data_get($reservationData, 'category.name')
        ?? data_get($reservationData, 'category.english_name')
        ?? data_get($reservationData, 'category.title')
        ?? 'Category '.$context['category_id'];
@endphp

@section('content')
<div class="max-w-4xl">
    <div class="flex items-center gap-3 mb-6">
        <a href="{{ route('user.bookings.index') }}" class="w-9 h-9 rounded-xl border border-slate-200 bg-white text-slate-500 flex items-center justify-center hover:text-slate-900 hover:border-slate-300 transition" aria-label="Back to bookings">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        </a>
        <div>
            <h2 class="text-xl font-bold text-slate-900">Reschedule SVP Reservation #{{ $reservation }}</h2>
            <p class="text-sm text-slate-500 mt-0.5">Choose a new city, test center, date, and session. SVP keeps the same reservation ID.</p>
        </div>
    </div>

    @if ($svpError)
        <div class="mb-6 px-4 py-3 bg-amber-50 border border-amber-200 text-amber-700 rounded-xl text-sm">{{ $svpError }}</div>
    @endif

    <div class="mb-6 rounded-2xl border border-indigo-200 bg-indigo-50 p-5">
        <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-indigo-500">Reservation identity</p>
                <p class="mt-1 text-lg font-bold text-indigo-950">{{ $candidateName }}</p>
                <p class="text-sm text-indigo-800">{{ $occupationName }} · {{ $categoryName }}</p>
            </div>
            <div class="md:text-right text-sm text-indigo-800">
                <p>Current exam date</p>
                <p class="font-semibold text-indigo-950">{{ $context['current_exam_date'] ?? 'Not supplied by SVP' }}</p>
                <p class="mt-2 text-xs">Occupation and category stay fixed. Location and schedule are selectable below.</p>
            </div>
        </div>
    </div>

    <form method="POST" action="{{ route('user.bookings.svp-reschedule.submit', ['reservation' => $reservation]) }}" class="space-y-6" id="reschedule-form">
        @csrf
        <input type="hidden" name="occupation_id" id="occupation_id" value="{{ old('occupation_id', $context['occupation_id']) }}">
        <input type="hidden" name="category_id" id="category_id" value="{{ old('category_id', $context['category_id']) }}">
        <input type="hidden" name="methodology" id="methodology" value="{{ old('methodology', $context['methodology'] ?? config('svp.default_methodology', 'in_person')) }}">

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
                <p class="text-xs font-medium text-slate-400 uppercase tracking-wide mb-2">Portal wallet balance</p>
                <p class="text-2xl font-bold text-slate-900">{{ number_format($wallet?->available_balance ?? 0, 2) }} <span class="text-sm font-medium text-slate-500">BDT</span></p>
                <p class="text-xs text-slate-400 mt-1">Reserved: {{ number_format($wallet?->reserved_balance ?? 0, 2) }} BDT</p>
                <p class="text-xs text-slate-500 mt-3">The portal booking fee is separate from SVP credit or card payment and is deducted only after SVP confirms the reschedule.</p>
            </div>
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
                <label for="candidate_id" class="block text-xs font-medium text-slate-400 uppercase tracking-wide mb-2">Candidate / SVP profile</label>
                <select name="candidate_id" id="candidate_id" required class="w-full rounded-xl border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
                    <option value="">Select candidate…</option>
                    @foreach ($candidates as $candidate)
                        <option value="{{ $candidate->id }}" {{ old('candidate_id', $selectedCandidateId) == $candidate->id ? 'selected' : '' }}>{{ $candidate->full_name ?: ('Candidate #'.$candidate->id) }}{{ $candidate->svp_user_id ? ' · SVP '.$candidate->svp_user_id : '' }}</option>
                    @endforeach
                </select>
                @error('candidate_id')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                @if ($candidates->isEmpty())
                    <p class="text-xs text-amber-600 mt-2">No candidate profile is synced. Sign in with SVP again before rescheduling.</p>
                @endif
            </div>
        </div>

        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 space-y-4">
            <p class="text-xs font-medium text-slate-400 uppercase tracking-wide">Fixed exam identity</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <p class="text-xs text-slate-500 mb-1">Occupation</p>
                    <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-800">{{ $occupationName }} <span class="text-slate-400">(ID {{ $context['occupation_id'] }})</span></div>
                </div>
                <div>
                    <p class="text-xs text-slate-500 mb-1">Category</p>
                    <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-800">{{ $categoryName }} <span class="text-slate-400">(ID {{ $context['category_id'] }})</span></div>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 space-y-4">
            <p class="text-xs font-medium text-slate-400 uppercase tracking-wide">New SVP location</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="city_id" class="block text-sm font-medium text-slate-700 mb-1">City</label>
                    <select name="city" id="city_id" required class="w-full rounded-xl border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
                        <option value="">Loading live cities…</option>
                    </select>
                    @error('city')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                </div>
                <div id="test-center-section" style="display:none;">
                    <label for="test_center_id" class="block text-sm font-medium text-slate-700 mb-1">Test center</label>
                    <select name="test_center_id" id="test_center_id" required class="w-full rounded-xl border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
                        <option value="">Select a city first…</option>
                    </select>
                    <input type="hidden" name="test_center_name" id="test_center_name" value="{{ old('test_center_name') }}">
                    <p id="center-summary" class="text-xs text-slate-400 mt-1">Select a city to load every live SVP test center for this category.</p>
                    @error('test_center_id')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                </div>
            </div>
        </div>

        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 space-y-4">
            <p class="text-xs font-medium text-slate-400 uppercase tracking-wide">New session and date</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="exam_session_id" class="block text-sm font-medium text-slate-700 mb-1">Available session</label>
                    <select name="exam_session_id" id="exam_session_id" required class="w-full rounded-xl border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
                        <option value="">Select a test center first…</option>
                    </select>
                    <input type="hidden" name="exam_session_name" id="exam_session_name" value="{{ old('exam_session_name') }}">
                    <input type="hidden" name="temporary_hold_id" id="temporary_hold_id" value="{{ old('temporary_hold_id') }}">
                    <input type="hidden" name="temporary_hold_expires_at" id="temporary_hold_expires_at" value="">
                    <div id="session-shift-summary" class="hidden mt-3 rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs text-slate-600"></div>
                    <p id="session-center-error" class="hidden mt-2 rounded-lg border border-red-200 bg-red-50 p-2 text-xs text-red-700"></p>
                    @error('exam_session_id')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                    @error('temporary_hold_id')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="exam_date" class="block text-sm font-medium text-slate-700 mb-1">Exam date <span class="text-slate-400 font-normal">(from selected session)</span></label>
                    <input type="date" name="exam_date" id="exam_date" value="{{ old('exam_date') }}" required readonly class="w-full rounded-xl border-slate-200 bg-slate-50 text-sm focus:border-brand-500 focus:ring-brand-500">
                    <p id="date-error" class="hidden text-red-600 text-xs mt-1"></p>
                    @error('exam_date')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                </div>
            </div>

            <div id="temporary-hold-panel" class="hidden rounded-xl border border-amber-200 bg-amber-50 p-4">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                    <div>
                        <p class="text-sm font-semibold text-amber-900">Temporary SVP seat hold</p>
                        <p id="temporary-hold-status" class="text-xs text-amber-800 mt-1">Create a temporary hold before confirming this reschedule.</p>
                    </div>
                    <button type="button" id="create-temporary-hold" disabled class="inline-flex items-center justify-center px-4 py-2 bg-amber-600 hover:bg-amber-700 disabled:opacity-50 disabled:cursor-not-allowed text-white text-sm font-semibold rounded-xl transition">Create temporary hold</button>
                </div>
            </div>

            <div>
                <label for="language_code" class="block text-sm font-medium text-slate-700 mb-1">SVP exam language</label>
                <select name="language_code" id="language_code" required class="w-full md:w-1/2 rounded-xl border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
                    @foreach (config('svp.languages', []) as $language)
                        <option value="{{ $language['code'] }}" {{ old('language_code', config('svp.default_language_code', 'LOABB')) === $language['code'] ? 'selected' : '' }}>
                            {{ $language['english_name'] }} ({{ $language['code'] }}) · {{ ucfirst($language['exam_engine_name']) }} · {{ $language['question_count'] }} questions
                        </option>
                    @endforeach
                </select>
                <p class="text-xs text-slate-400 mt-1">Selected language: Bengali · Prometric · code <code>LOABB</code>. This code is sent to SVP; <code>bn</code> is the ISO language identifier.</p>
            </div>

            <div id="svp-credit-panel" class="rounded-xl border border-sky-200 bg-sky-50 p-4">
                <p class="text-sm font-semibold text-sky-900">SVP reservation payment routing</p>
                <p id="svp-credit-status" class="text-xs text-sky-800 mt-1">Select a candidate to check the live SVP credit. If no credit is available, confirmation opens the official SVP card-payment page.</p>
                <p class="text-xs text-sky-700 mt-2">SVP credit/card payment is separate from the portal wallet fee.</p>
            </div>
        </div>

        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
            <label for="notes" class="block text-sm font-medium text-slate-700 mb-1">Notes <span class="text-slate-400 font-normal">(optional)</span></label>
            <textarea name="notes" id="notes" rows="3" maxlength="500" class="w-full rounded-xl border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">{{ old('notes') }}</textarea>
        </div>

        <div class="flex items-center gap-3">
            <button type="submit" id="confirm-reschedule-button" disabled class="inline-flex items-center gap-2 px-5 py-2.5 bg-gradient-to-r from-indigo-500 to-fuchsia-500 hover:from-indigo-600 hover:to-fuchsia-600 disabled:opacity-50 disabled:cursor-not-allowed text-white text-sm font-semibold rounded-xl shadow-lg shadow-indigo-500/25 transition">Confirm &amp; Reschedule</button>
            <a href="{{ route('user.bookings.index') }}" class="inline-flex items-center gap-2 px-5 py-2.5 bg-white border border-slate-200 text-slate-700 text-sm font-medium rounded-xl hover:bg-slate-50 transition">Cancel</a>
        </div>
    </form>
</div>

<script>
(function () {
    const form = document.getElementById('reschedule-form');
    const candidate = document.getElementById('candidate_id');
    const occupation = document.getElementById('occupation_id');
    const category = document.getElementById('category_id');
    const city = document.getElementById('city_id');
    const center = document.getElementById('test_center_id');
    const centerName = document.getElementById('test_center_name');
    const centerSection = document.getElementById('test-center-section');
    const centerSummary = document.getElementById('center-summary');
    const session = document.getElementById('exam_session_id');
    const sessionName = document.getElementById('exam_session_name');
    const date = document.getElementById('exam_date');
    const sessionSummary = document.getElementById('session-shift-summary');
    const centerError = document.getElementById('session-center-error');
    const dateError = document.getElementById('date-error');
    const holdPanel = document.getElementById('temporary-hold-panel');
    const holdButton = document.getElementById('create-temporary-hold');
    const holdStatus = document.getElementById('temporary-hold-status');
    const holdId = document.getElementById('temporary_hold_id');
    const holdExpiry = document.getElementById('temporary_hold_expires_at');
    const confirmButton = document.getElementById('confirm-reschedule-button');
    const creditStatus = document.getElementById('svp-credit-status');
    let holdRequest = null;
    let creditRequest = null;
    let sessionSnapshot = [];

    const esc = value => String(value ?? '').replace(/[&<>\"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;',"'":'&#039;'}[ch]));
    const sessionDate = item => String(item?.exam_date || item?.test_date || item?.date || item?.start_date_in_browser_time_zone || item?.start_date_in_tc_time_zone || '').substring(0, 10);
    const sessionCenterId = item => String(item?.test_center_id ?? item?.site_id ?? item?.center_id ?? item?.test_center?.id ?? item?.site?.id ?? item?.center?.id ?? '');
    const sessionCenterName = item => item?.test_center_name || item?.site_name || item?.center_name || item?.test_center?.name || item?.site?.name || item?.center?.name || 'Unknown center';
    const shiftLabel = (item, index) => {
        const text = String(item?.shift || item?.session_name || item?.name || '').toLowerCase();
        const match = text.match(/(?:shift|session)\s*([1-9][0-9]*)/);
        const number = match ? Number(match[1]) : index + 1;
        return number === 1 ? 'First Shift' : number === 2 ? 'Second Shift' : number === 3 ? 'Third Shift' : number === 4 ? 'Fourth Shift' : 'Shift ' + number;
    };
    const formatExpiry = value => { const parsed = new Date(value); return Number.isNaN(parsed.getTime()) ? String(value || '') : parsed.toLocaleString(); };

    async function getJson(url) {
        const response = await fetch(url, {headers: {'Accept': 'application/json'}});
        const body = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(body.error || 'SVP lookup failed.');
        return body;
    }

    function setLoading(select, loading) {
        if (!select) return;
        select.disabled = loading;
        select.dataset.loading = loading ? '1' : '0';
        if (loading) select.innerHTML = '<option value="">Loading live SVP data…</option>';
    }

    function resetHold(message) {
        holdId.value = '';
        holdExpiry.value = '';
        confirmButton.disabled = true;
        holdButton.disabled = true;
        if (message) holdStatus.textContent = message;
        holdPanel.classList.toggle('hidden', !session.value);
    }

    function canCreateHold() {
        return Boolean(occupation.value && category.value && city.value && center.value && centerName.value && session.value && date.value);
    }

    function syncActionButtons() {
        if (!holdId.value) holdButton.disabled = !canCreateHold();
        confirmButton.disabled = !(holdId.value && candidate.value);
    }

    function renderSessions(items) {
        sessionSnapshot = Array.isArray(items) ? items : [];
        session.innerHTML = '<option value="">Select…</option>';
        const counts = {};
        sessionSnapshot.forEach(item => {
            const id = item.id || item.exam_session_id || '';
            const dateValue = sessionDate(item);
            const index = counts[dateValue] || 0;
            counts[dateValue] = index + 1;
            const option = document.createElement('option');
            option.value = id;
            option.dataset.name = item.name || item.session_name || shiftLabel(item, index);
            option.dataset.centerId = sessionCenterId(item);
            option.dataset.centerName = sessionCenterName(item);
            option.dataset.date = dateValue;
            option.textContent = (dateValue || 'Unknown date') + ' — ' + shiftLabel(item, index) + ' · ' + (option.dataset.centerName || option.dataset.name || 'Unknown test center');
            option.disabled = !option.dataset.centerId || option.dataset.centerId !== String(center.value);
            session.appendChild(option);
        });
        const grouped = {};
        sessionSnapshot.forEach((item, index) => {
            const d = sessionDate(item) || 'Unknown date';
            grouped[d] = grouped[d] || [];
            const id = item.id || item.exam_session_id || '';
            const matches = sessionCenterId(item) === String(center.value);
            grouped[d].push('<div class="ml-2 ' + (matches ? 'text-slate-600' : 'text-red-700') + '"><span class="font-medium">' + esc(shiftLabel(item, index)) + '</span> · Session ' + esc(id) + ' · ' + esc(sessionCenterName(item)) + (matches ? '' : ' · <strong>BLOCKED: center mismatch</strong>') + '</div>');
        });
        const html = Object.keys(grouped).sort().map(d => '<div class="mb-2 last:mb-0"><div class="font-semibold text-slate-700">' + esc(d) + '</div>' + grouped[d].join('') + '</div>').join('');
        sessionSummary.innerHTML = '<div class="mb-1 font-medium text-slate-700">Sessions grouped by date and shift</div>' + (html || '<div>No sessions returned for this center.</div>');
        sessionSummary.classList.remove('hidden');
    }

    async function loadCities() {
        try {
            setLoading(city, true);
            const body = await getJson('{{ route('user.bookings.lookup.cities') }}?category_id=' + encodeURIComponent(category.value));
            const items = Array.isArray(body.data) ? body.data : [];
            city.innerHTML = '<option value="">Select city…</option>';
            items.forEach(item => {
                const name = item.name || item.city || item.city_name || item;
                if (!name) return;
                const option = document.createElement('option'); option.value = name; option.textContent = name; city.appendChild(option);
            });
            @if(old('city')) city.value = @json(old('city')); @endif
            if (city.value) city.dispatchEvent(new Event('change'));
        } catch (error) {
            city.innerHTML = '<option value="">Could not load cities</option>';
            console.error(error);
        } finally { city.disabled = false; }
    }

    async function loadCenters() {
        resetHold('Select a session and date, then create a temporary hold before confirming the reschedule.');
        centerSection.style.display = 'none';
        center.innerHTML = '<option value="">Loading live test centers…</option>';
        session.innerHTML = '<option value="">Select a test center first…</option>';
        sessionSummary.classList.add('hidden'); date.value = ''; centerName.value = '';
        if (!city.value) return;
        try {
            setLoading(center, true);
            const body = await getJson('{{ route('user.bookings.lookup.test-centers') }}?city=' + encodeURIComponent(city.value) + '&category_id=' + encodeURIComponent(category.value));
            const items = body?.data?.test_centers || (Array.isArray(body.data) ? body.data : []);
            center.innerHTML = '<option value="">Select test center…</option>';
            items.forEach(item => {
                const id = item.id || item.test_center_id || item.site_id || '';
                if (!id) return;
                const name = item.name || item.english_name || item.site_name || 'SVP test center';
                const option = document.createElement('option'); option.value = id; option.dataset.name = name; option.textContent = name; center.appendChild(option);
            });
            centerSummary.textContent = 'SVP returned ' + items.length + ' live test center(s) for ' + city.value + '.';
            centerSection.style.display = '';
            @if(old('test_center_id')) center.value = @json(old('test_center_id')); if (center.value) center.dispatchEvent(new Event('change')); @endif
        } catch (error) { center.innerHTML = '<option value="">Could not load test centers</option>'; console.error(error); }
        finally { center.disabled = false; }
    }

    async function loadSessions() {
        resetHold('Select a live SVP session, then create a temporary hold before confirming the reschedule.');
        session.innerHTML = '<option value="">Loading live sessions…</option>'; date.value = ''; sessionName.value = '';
        if (!city.value || !center.value) return;
        try {
            setLoading(session, true);
            const params = new URLSearchParams({city: city.value, category_id: category.value, test_center_id: center.value});
            const body = await getJson('{{ route('user.bookings.lookup.sessions') }}?' + params.toString());
            renderSessions(body?.data?.sessions || body?.data?.exam_sessions || body?.sessions || body?.exam_sessions || []);
        } catch (error) { session.innerHTML = '<option value="">Could not load sessions</option>'; console.error(error); }
        finally { session.disabled = false; }
    }

    async function loadCredit() {
        if (!candidate.value || !occupation.value) { creditStatus.textContent = 'Select a candidate to check the live SVP credit.'; return; }
        if (creditRequest) return;
        creditStatus.textContent = 'Checking the live SVP reservation credit…';
        const params = new URLSearchParams({candidate_id: candidate.value, occupation_id: occupation.value, methodology: document.getElementById('methodology').value});
        creditRequest = getJson('{{ route('user.bookings.credit-status') }}?' + params.toString()).then(body => {
            const credits = Number(body?.data?.credits ?? 0);
            creditStatus.textContent = credits > 0 ? 'SVP reports ' + credits + ' reservation credit. One credit will be used; no SVP card page will open.' : 'No SVP reservation credit is available. After the hold, the official SVP card-payment page will open.';
        }).catch(error => { creditStatus.textContent = 'SVP credit status could not be loaded. Refresh the SVP login before confirming.'; console.error(error); }).finally(() => { creditRequest = null; });
        await creditRequest;
    }

    async function createHold() {
        if (holdRequest) return;
        const selected = session.options[session.selectedIndex];
        if (!selected || !selected.value || !selected.dataset.centerId || selected.dataset.centerId !== String(center.value)) {
            holdStatus.textContent = 'The selected session does not belong to the selected test center.'; return;
        }
        const payload = {occupation_id: occupation.value, category_id: category.value, city: city.value, test_center_id: center.value, test_center_name: centerName.value, exam_session_id: session.value, exam_date: date.value};
        if (Object.values(payload).some(value => !value)) { holdStatus.textContent = 'Select city, center, session, and date first.'; return; }
        holdButton.disabled = true; holdStatus.textContent = 'Creating the live SVP temporary hold…';
        holdRequest = fetch('{{ route('user.bookings.temporary-hold') }}', {method: 'POST', headers: {'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]')?.content || ''}, body: JSON.stringify(payload)}).then(async response => {
            const body = await response.json().catch(() => ({}));
            if (!response.ok || body.success === false) throw new Error(body.error || 'SVP could not create the temporary hold.');
            const selection = body.selection || {};
            if (selection.exam_session_id) session.value = selection.exam_session_id;
            if (selection.exam_date) date.value = selection.exam_date;
            if (selection.exam_session_name) sessionName.value = selection.exam_session_name;
            const hold = body.data || body; const id = hold.id ?? hold.hold_id ?? hold.temporary_hold_id;
            if (!id) throw new Error('SVP returned no temporary hold ID.');
            holdId.value = id; holdExpiry.value = hold.expired_at || hold.expires_at || '';
            holdStatus.textContent = 'Hold #' + id + ' created' + (holdExpiry.value ? ' — expires ' + formatExpiry(holdExpiry.value) : '') + '. You may now confirm the reschedule.';
            holdStatus.classList.remove('text-red-700'); syncActionButtons();
        }).catch(error => { holdId.value = ''; holdExpiry.value = ''; confirmButton.disabled = true; holdStatus.textContent = error.message; holdStatus.classList.add('text-red-700'); }).finally(() => { holdRequest = null; syncActionButtons(); });
        await holdRequest;
    }

    city.addEventListener('change', loadCenters);
    center.addEventListener('change', async function () {
        const option = center.options[center.selectedIndex]; centerName.value = option?.dataset?.name || option?.textContent?.replace(/\s+—\s+SVP ID:.*$/, '') || '';
        await loadSessions();
    });
    session.addEventListener('change', function () {
        const option = session.options[session.selectedIndex];
        centerError.classList.add('hidden'); dateError.classList.add('hidden');
        if (option?.dataset?.centerId !== String(center.value)) { session.value = ''; resetHold('The selected session belongs to another test center and is blocked.'); const sessionCenterName = option?.dataset?.centerName || option?.dataset?.name || 'another test center'; const selectedCenterName = center.options[center.selectedIndex]?.dataset?.name || center.options[center.selectedIndex]?.textContent || 'the selected test center'; centerError.textContent = 'Blocked: session center "' + sessionCenterName + '" does not match selected center "' + selectedCenterName + '".'; centerError.classList.remove('hidden'); return; }
        sessionName.value = option?.dataset?.name || option?.textContent || ''; date.value = option?.dataset?.date || ''; holdPanel.classList.remove('hidden'); resetHold('Create a temporary hold for this exact session and date before confirming.'); syncActionButtons();
        if (!/^\d{4}-\d{2}-\d{2}$/.test(date.value)) { dateError.textContent = 'The selected SVP session did not return an exam date.'; dateError.classList.remove('hidden'); holdButton.disabled = true; }
    });
    candidate.addEventListener('change', function () { loadCredit(); syncActionButtons(); });
    form.addEventListener('submit', event => {
        if (!candidate.value) {
            event.preventDefault();
            holdPanel.classList.remove('hidden');
            holdStatus.textContent = 'Select a candidate profile before confirming the reschedule.';
            return;
        }
        if (!holdId.value) {
            event.preventDefault();
            holdPanel.classList.remove('hidden');
            holdStatus.textContent = 'Create a live SVP temporary hold before confirming the reschedule.';
        }
    });
    holdButton.addEventListener('click', createHold);
    loadCredit(); loadCities();
})();
</script>
@endsection
