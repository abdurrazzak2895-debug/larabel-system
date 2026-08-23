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
<div class="max-w-3xl">
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

        <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-4 space-y-4">
            <p class="text-xs font-medium text-slate-400 uppercase tracking-wide">New SVP location</p>
            <div>
                <label for="city_id" class="block text-sm font-medium text-slate-700 mb-1">City</label>
                <select name="city" id="city_id" required class="w-full rounded-xl border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
                    <option value="">Loading live cities…</option>
                </select>
                @error('city')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
            </div>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <label for="language_code" class="block text-sm font-medium text-slate-700 mb-1">SVP exam language</label>
            <select name="language_code" id="language_code" required disabled class="w-full rounded-xl border-slate-200 bg-slate-50 text-sm focus:border-brand-500 focus:ring-brand-500">
                <option value="">Select a live SVP exam language…</option>
            </select>
            <p id="language-error" class="hidden text-red-600 text-xs mt-1"></p>
            <p class="text-xs text-slate-400 mt-1">Languages are loaded live from Portal Availability for the fixed reservation occupation. No language is preselected.</p>
        </div>

        <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-4 space-y-4">
            <p class="text-xs font-medium text-slate-400 uppercase tracking-wide">Available Sessions — date-first PACC reschedule</p>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Available Exam Date</label>
                @include('user.bookings.partials.svp-calendar', ['calendarId' => 'reschedule-availability-calendar'])
                <select id="available_session_date" aria-hidden="true" tabindex="-1"
                    class="hidden w-full rounded-xl border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
                    <option value="">Select a live available date first…</option>
                </select>
                <p id="date-hint" class="text-xs text-slate-400 mt-1">Only live Portal Availability dates for the selected city are clickable. Pick a date to load that date’s available center slots.</p>
            </div>

            <div id="test-center-section" style="display:none;" class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                <div class="mb-2 flex items-center justify-between gap-3">
                    <span class="text-sm font-medium text-slate-700">Test center slot</span>
                    <span class="text-[11px] text-slate-400">Click one card to select</span>
                </div>
                <input type="hidden" name="test_center_id" id="test_center_id" value="{{ old('test_center_id') }}">
                <input type="hidden" name="test_center_name" id="test_center_name" value="{{ old('test_center_name') }}">
                <p id="center-summary" class="text-xs text-slate-500 mt-1">Select a live date to load every Portal Availability center slot for that date.</p>
                @include('user.bookings.partials.pacc-availability-response', [
                    'componentId' => 'reschedule-center-response',
                    'mode' => 'centers',
                    'centerSelectId' => 'test_center_id',
                    'sessionSelectId' => 'exam_session_id',
                ])
                @error('test_center_id')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
            </div>

            <div>
                <input type="hidden" name="exam_session_id" id="exam_session_id" value="{{ old('exam_session_id') }}">
                <input type="hidden" name="exam_session_name" id="exam_session_name" value="{{ old('exam_session_name') }}">
                <input type="hidden" name="exam_date" id="exam_date" value="{{ old('exam_date') }}">
                <input type="hidden" name="temporary_hold_id" id="temporary_hold_id" value="{{ old('temporary_hold_id') }}">
                <input type="hidden" name="temporary_hold_expires_at" id="temporary_hold_expires_at" value="">
                @include('user.bookings.partials.pacc-availability-response', [
                    'componentId' => 'reschedule-session-response',
                    'mode' => 'sessions',
                    'centerSelectId' => 'test_center_id',
                    'sessionSelectId' => 'exam_session_id',
                    'sessionNameInputId' => 'exam_session_name',
                    'dateInputId' => 'exam_date',
                ])
                <p id="session-center-error" class="hidden mt-2 rounded-lg border border-red-200 bg-red-50 p-2 text-xs text-red-700"></p>
                <p id="date-error" class="hidden text-red-600 text-xs mt-1"></p>
                @error('exam_session_id')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                @error('exam_date')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                @error('temporary_hold_id')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
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

            <div id="svp-credit-panel" class="rounded-xl border border-sky-200 bg-sky-50 p-4">
                <p class="text-sm font-semibold text-sky-900">SVP reservation payment routing</p>
                <p id="svp-credit-status" class="text-xs text-sky-800 mt-1">Select a candidate to check the live SVP credit. If no credit is available, confirmation opens the official SVP card-payment page.</p>
                <p class="text-xs text-sky-700 mt-2">SVP credit/card payment is separate from the portal wallet fee.</p>
            </div>
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
    const availableDate = document.getElementById('available_session_date');
    const session = document.getElementById('exam_session_id');
    const sessionName = document.getElementById('exam_session_name');
    const date = document.getElementById('exam_date');
    const centerResponse = window.PaccAvailabilityInstances?.['reschedule-center-response'];
    const sessionResponse = window.PaccAvailabilityInstances?.['reschedule-session-response'];
    const centerError = document.getElementById('session-center-error');
    const dateError = document.getElementById('date-error');
    const language = document.getElementById('language_code');
    const languageError = document.getElementById('language-error');
    const holdPanel = document.getElementById('temporary-hold-panel');
    const holdButton = document.getElementById('create-temporary-hold');
    const holdStatus = document.getElementById('temporary-hold-status');
    const holdId = document.getElementById('temporary_hold_id');
    const holdExpiry = document.getElementById('temporary_hold_expires_at');
    const confirmButton = document.getElementById('confirm-reschedule-button');
    const creditStatus = document.getElementById('svp-credit-status');
    const oldCity = @json(old('city', ''));
    const oldDate = @json(old('exam_date', ''));
    const oldCenter = @json(old('test_center_id', ''));
    const oldLanguage = @json(old('language_code', ''));
    let holdRequest = null;
    let creditRequest = null;
    let sessionSnapshot = [];
    let availableDateCatalog = [];
    let availabilityCalendar = null;
    const sessionLookupCache = new Map();
    const sessionLookupRequests = new Map();

    function mountAvailabilityCalendar() {
        if (!window.SvpCalendar || availabilityCalendar) return;
        availabilityCalendar = window.SvpCalendar.create('reschedule-availability-calendar', {
            emptyText: 'Pick a city to load live open exam dates.',
            onSelect: function (value) {
                if (!value || value === availableDate.value) return;
                availableDate.value = value;
                availableDate.dispatchEvent(new Event('change'));
            }
        });
    }
    mountAvailabilityCalendar();

    const esc = value => String(value ?? '').replace(/[&<>\"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;',"'":'&#039;'}[ch]));
    const normalizeDate = value => String(value || '').substring(0, 10);
    const sessionDate = item => normalizeDate(item?.exam_date || item?.test_date || item?.date || item?.start_date_in_browser_time_zone || item?.start_date_in_tc_time_zone);
    const sessionCenterId = item => String(item?.test_center_id ?? item?.site_id ?? item?.center_id ?? item?.test_center?.id ?? item?.site?.id ?? item?.center?.id ?? '');
    const sessionCenterName = item => item?.test_center_name || item?.site_name || item?.center_name || item?.test_center?.name || item?.site?.name || item?.center?.name || centerName.value || 'Selected test center';
    const sessionTime = item => String(item?.test_time || item?.start_time || item?.time || item?.start_at || '').replace(/^\d{4}-\d{2}-\d{2}[T ]/, '').trim();
    const sessionSeatCount = item => {
        const value = item?.available_seats ?? item?.availableSeats ?? item?.remaining_seats ?? item?.remainingSeats ?? item?.seats ?? null;
        return value === null || value === '' || Number.isNaN(Number(value)) ? null : Number(value);
    };
    const sessionDisplayName = (item, index) => String(item?.session_name || item?.name || item?.label || item?.title || '').trim() || 'Session ' + (index + 1);
    const shiftLabel = (item, index) => {
        const text = String(item?.shift || item?.session_name || item?.name || '').toLowerCase();
        const match = text.match(/(?:shift|session)\s*([1-9][0-9]*)/);
        const number = match ? Number(match[1]) : index + 1;
        return number === 1 ? 'First Shift' : number === 2 ? 'Second Shift' : number === 3 ? 'Third Shift' : number === 4 ? 'Fourth Shift' : 'Shift ' + number;
    };
    const sessionOptionLabel = (item, index) => {
        const details = [sessionDisplayName(item, index)];
        const time = sessionTime(item);
        const seats = sessionSeatCount(item);
        if (time) details.push('Time: ' + time);
        details.push(seats === null ? 'Live seats unavailable' : 'Seats: ' + seats);
        return details.join(' · ');
    };

    async function getJson(url) {
        const response = await fetch(url, {headers: {'Accept': 'application/json'}});
        const body = await response.json().catch(() => ({}));
        if (response.status === 401) {
            window.location.assign(body.login_url || '{{ route('svp.login.form', ['force' => 1]) }}');
            throw new Error(body.error || 'Your SVP session has expired. Please sign in again.');
        }
        if (!response.ok) throw new Error(body.error || body.message || 'Live SVP lookup failed.');
        return body;
    }

    function setLoading(select, loading) {
        if (!select) return;
        select.disabled = loading;
        select.dataset.loading = loading ? '1' : '0';
    }

    function showError(element, message) {
        if (!element) return;
        element.textContent = message || '';
        element.classList.toggle('hidden', !message);
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
        return Boolean(occupation.value && category.value && city.value && center.value && centerName.value && session.value && date.value && language.value);
    }

    function syncActionButtons() {
        if (!holdId.value) holdButton.disabled = !canCreateHold();
        confirmButton.disabled = !(holdId.value && candidate.value);
    }

    function renderAvailableDates() {
        const dates = Array.from(new Set((availableDateCatalog || []).map(item => normalizeDate(typeof item === 'string' ? item : item?.exam_date || item?.date || item?.test_date || '')).filter(value => /^\d{4}-\d{2}-\d{2}$/.test(value)))).sort();
        availableDate.innerHTML = '<option value="">Select an available date…</option>';
        dates.forEach(value => {
            const option = document.createElement('option');
            option.value = value;
            option.textContent = value;
            availableDate.appendChild(option);
        });
        availableDate.disabled = dates.length === 0;
        availabilityCalendar?.setDates(dates);
        availabilityCalendar?.setSelected(availableDate.value, true);
        return dates;
    }

    function clearSessionValue() {
        session.value = '';
        session.dataset.name = '';
        session.dataset.date = '';
        session.dataset.centerId = '';
        sessionName.value = '';
    }

    function resetSessionSelection() {
        clearSessionValue();
        sessionResponse?.clear();
    }

    function renderSessions(items, selectedDate) {
        sessionSnapshot = Array.isArray(items) ? items : [];
        const visible = sessionSnapshot.filter(item => !selectedDate || sessionDate(item) === selectedDate);
        clearSessionValue();
        if (selectedDate) {
            sessionResponse?.renderSessions(visible, {
                date: selectedDate,
                emptyText: 'No exact SVP sessions returned for the selected center and date.'
            });
        } else {
            sessionResponse?.clear();
        }
        if (!selectedDate) return;
    }

    async function loadLiveLanguages() {
        if (!occupation.value || !language) return;
        try {
            setLoading(language, true);
            const body = await getJson('{{ route('user.bookings.lookup.languages') }}?occupation_id=' + encodeURIComponent(occupation.value));
            const items = Array.isArray(body?.data?.languages) ? body.data.languages : (Array.isArray(body?.data) ? body.data : []);
            language.innerHTML = '<option value="">Select a live SVP exam language…</option>';
            items.forEach(function (item) {
                const code = item.code || item.language_code || item.id || '';
                if (!code) return;
                const name = item.english_name || item.name || item.arabic_name || code;
                const option = document.createElement('option');
                option.value = code;
                option.textContent = name + ' (' + code + ')' + (item.exam_engine_name ? ' · ' + item.exam_engine_name : '') + (item.question_count ? ' · ' + item.question_count + ' questions' : '');
                language.appendChild(option);
            });
            language.disabled = items.length === 0;
            if (oldLanguage && items.some(item => String(item.code || item.language_code || item.id || '') === String(oldLanguage))) language.value = oldLanguage;
            showError(languageError, items.length ? '' : 'No live exam languages were returned for this occupation.');
        } catch (error) {
            language.innerHTML = '<option value="">Could not load live languages</option>';
            language.disabled = true;
            showError(languageError, error.message);
            console.error(error);
        } finally {
            language.disabled = !language.options.length || language.options.length === 1;
        }
    }

    async function loadPortalDatesForCity(cityValue, restoreOldDate = false) {
        if (!cityValue || !category.value) return;
        try {
            setLoading(availableDate, true);
            const body = await getJson('{{ route('user.bookings.lookup.dates') }}?city=' + encodeURIComponent(cityValue) + '&category_id=' + encodeURIComponent(category.value));
            availableDateCatalog = Array.isArray(body?.data?.dates) ? body.data.dates : (Array.isArray(body?.data) ? body.data : []);
            const dates = renderAvailableDates();
            if (restoreOldDate && oldDate && dates.includes(String(oldDate).substring(0, 10))) {
                availableDate.value = String(oldDate).substring(0, 10);
                availabilityCalendar?.setSelected(availableDate.value, true);
                availableDate.dispatchEvent(new Event('change'));
            }
        } catch (error) {
            availableDateCatalog = [];
            renderAvailableDates();
            showError(dateError, error.message);
            console.error(error);
        } finally {
            setLoading(availableDate, false);
        }
    }

    async function loadTestCentersForDate(dateValue, restoreOldCenter = false) {
        if (!dateValue || !city.value || !category.value || !occupation.value || !language.value) {
            if (dateValue && !language.value) centerSummary.textContent = 'Select a live SVP exam language to load center slots for this date.';
            return;
        }
        try {
            setLoading(center, true);
            const url = '{{ route('user.bookings.lookup.test-centers') }}?city=' + encodeURIComponent(city.value) + '&category_id=' + encodeURIComponent(category.value) + '&date=' + encodeURIComponent(dateValue) + '&occupation_id=' + encodeURIComponent(occupation.value) + '&language_code=' + encodeURIComponent(language.value);
            const body = await getJson(url);
            const items = body?.data?.test_centers || (Array.isArray(body?.data) ? body.data : []);
            center.value = '';
            center.dataset.name = '';
            centerName.value = '';
            centerResponse?.renderCenters(items, {
                city: city.value,
                date: dateValue,
                emptyText: 'No live test-center slots returned for the selected date.'
            });
            centerSection.style.display = items.length ? '' : 'none';
            prefetchSessionsForCenters(items, dateValue);
            centerSummary.textContent = items.length
                ? 'Portal Availability returned ' + items.length + ' center slot' + (items.length === 1 ? '' : 's') + ' for ' + city.value + ' on ' + dateValue + '. Click one card to load exact SVP sessions.'
                : 'No center slots returned for ' + city.value + ' on ' + dateValue + '.';
            if (restoreOldCenter && oldCenter) {
                const restored = items.find(function (item) {
                    return String(item.id || item.test_center_id || item.site_id || item.center_id || '') === String(oldCenter);
                });
                if (restored) {
                    const restoredName = restored.name || restored.english_name || restored.test_center_name || restored.site_name || restored.center_name || 'SVP test center';
                    center.value = String(oldCenter);
                    center.dataset.name = restoredName;
                    centerName.value = restoredName;
                    centerResponse?.syncSelection();
                    center.dispatchEvent(new Event('change'));
                }
            }
        } catch (error) {
            centerSection.style.display = 'none';
            centerSummary.textContent = error.message;
            console.error(error);
        } finally {
            setLoading(center, false);
        }
    }

    function sessionLookupKey(centerId, dateValue) {
        return [city.value, category.value, centerId, dateValue].join('|');
    }

    function sessionRowsFromResponse(body) {
        return body?.data?.sessions || body?.data?.exam_sessions || body?.sessions || body?.exam_sessions || [];
    }

    function requestSessionsForCenter(centerId, dateValue) {
        const key = sessionLookupKey(centerId, dateValue);
        if (sessionLookupCache.has(key)) return Promise.resolve(sessionLookupCache.get(key));
        if (sessionLookupRequests.has(key)) return sessionLookupRequests.get(key);
        const params = new URLSearchParams({city: city.value, category_id: category.value, test_center_id: centerId, exam_date: dateValue});
        const request = getJson('{{ route('user.bookings.lookup.sessions') }}?' + params.toString())
            .then(body => {
                const sessions = Array.isArray(sessionRowsFromResponse(body)) ? sessionRowsFromResponse(body) : [];
                sessionLookupCache.set(key, sessions);
                return sessions;
            })
            .finally(() => sessionLookupRequests.delete(key));
        sessionLookupRequests.set(key, request);
        return request;
    }

    function prefetchSessionsForCenters(centers, dateValue) {
        const centerIds = [...new Set((Array.isArray(centers) ? centers : []).map(item => String(item.id || item.test_center_id || item.site_id || item.center_id || '')).filter(Boolean))];
        centerIds.forEach(centerId => {
            requestSessionsForCenter(centerId, dateValue).catch(error => console.debug('Automatic session prefetch failed', {centerId, dateValue, error}));
        });
    }

    async function loadSessionsForDate(dateValue) {
        if (!city.value || !center.value || !dateValue) return;
        const requestedCenterId = String(center.value);
        try {
            setLoading(session, true);
            const sessions = await requestSessionsForCenter(requestedCenterId, dateValue);
            if (requestedCenterId !== String(center.value) || dateValue !== availableDate.value) return;
            renderSessions(sessions, dateValue);
        } catch (error) {
            if (requestedCenterId !== String(center.value) || dateValue !== availableDate.value) return;
            sessionResponse?.renderSessions([], {date: dateValue, emptyText: error.message});
            clearSessionValue();
            showError(dateError, error.message);
            console.error(error);
        } finally {
            setLoading(session, false);
            syncActionButtons();
        }
    }

    async function loadCities() {
        try {
            setLoading(city, true);
            const body = await getJson('{{ route('user.bookings.lookup.cities') }}?category_id=' + encodeURIComponent(category.value));
            const items = Array.isArray(body?.data) ? body.data : [];
            city.innerHTML = '<option value="">Select city…</option>';
            items.forEach(function (item) {
                const value = item.name || item.city || item.city_name || item;
                if (!value) return;
                const option = document.createElement('option');
                option.value = value;
                option.textContent = value;
                city.appendChild(option);
            });
            if (oldCity && items.some(item => String(item.name || item.city || item.city_name || item) === String(oldCity))) {
                city.value = oldCity;
                await loadPortalDatesForCity(city.value, true);
            }
        } catch (error) {
            city.innerHTML = '<option value="">Could not load live cities</option>';
            console.error(error);
        } finally {
            setLoading(city, false);
        }
    }

    async function loadCredit() {
        if (!candidate.value || !occupation.value) {
            creditStatus.textContent = 'Select a candidate to check the live SVP credit.';
            return;
        }
        if (creditRequest) return;
        creditStatus.textContent = 'Checking the live SVP reservation credit…';
        const params = new URLSearchParams({candidate_id: candidate.value, occupation_id: occupation.value, methodology: document.getElementById('methodology').value});
        creditRequest = getJson('{{ route('user.bookings.credit-status') }}?' + params.toString()).then(body => {
            const credits = Number(body?.data?.credits ?? 0);
            creditStatus.textContent = credits > 0 ? 'SVP reports ' + credits + ' reservation credit. One credit will be used; no SVP card page will open.' : 'No SVP reservation credit is available. After the hold, the official SVP card-payment page will open.';
        }).catch(error => {
            creditStatus.textContent = 'SVP credit status could not be loaded. Refresh the SVP login before confirming.';
            console.error(error);
        }).finally(() => { creditRequest = null; });
        await creditRequest;
    }

    async function createHold() {
        if (holdRequest) return;
        const selected = {value: session.value, dataset: session.dataset};
        if (!selected || !selected.value || (selected.dataset.centerId && selected.dataset.centerId !== String(center.value))) {
            holdStatus.textContent = 'The selected session does not belong to the selected test center.';
            return;
        }
        const payload = {occupation_id: occupation.value, category_id: category.value, city: city.value, test_center_id: center.value, test_center_name: centerName.value, exam_session_id: session.value, exam_date: date.value, language_code: language.value};
        if (Object.values(payload).some(value => !value)) {
            holdStatus.textContent = 'Select language, city, date, center slot, session, and date first.';
            return;
        }
        holdButton.disabled = true;
        holdStatus.textContent = 'Creating the live SVP temporary hold…';
        holdRequest = fetch('{{ route('user.bookings.temporary-hold') }}', {method: 'POST', headers: {'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]')?.content || ''}, body: JSON.stringify(payload)}).then(async response => {
            const body = await response.json().catch(() => ({}));
            if (!response.ok || body.success === false) throw new Error(body.error || 'SVP could not create the temporary hold.');
            const selection = body.selection || {};
            if (selection.exam_session_id) {
                session.value = selection.exam_session_id;
                session.dataset.name = selection.exam_session_name || session.dataset.name || '';
                session.dataset.date = selection.exam_date || session.dataset.date || '';
            }
            if (selection.exam_date) date.value = selection.exam_date;
            if (selection.exam_session_name) {
                sessionName.value = selection.exam_session_name;
                session.dataset.name = selection.exam_session_name;
            }
            const hold = body.data || body;
            const id = hold.id ?? hold.hold_id ?? hold.temporary_hold_id;
            if (!id) throw new Error('SVP returned no temporary hold ID.');
            holdId.value = id;
            holdExpiry.value = hold.expired_at || hold.expires_at || '';
            holdStatus.textContent = 'Hold #' + id + ' created' + (holdExpiry.value ? ' — expires ' + new Date(holdExpiry.value).toLocaleString() : '') + '. You may now confirm the reschedule.';
            holdStatus.classList.remove('text-red-700');
            syncActionButtons();
        }).catch(error => {
            holdId.value = '';
            holdExpiry.value = '';
            confirmButton.disabled = true;
            holdStatus.textContent = error.message;
            holdStatus.classList.add('text-red-700');
        }).finally(() => { holdRequest = null; syncActionButtons(); });
        await holdRequest;
    }

    city.addEventListener('change', async function () {
        centerSection.style.display = 'none';
        center.value = '';
        center.dataset.name = '';
        resetSessionSelection();
        availableDateCatalog = [];
        sessionSnapshot = [];
        renderAvailableDates();
        sessionSummary?.classList.add('hidden');
        centerResponse?.clear();
        sessionResponse?.clear();
        centerName.value = '';
        sessionName.value = '';
        date.value = '';
        showError(dateError, '');
        resetHold('Select a live date, center slot, and exact session before creating a temporary hold.');
        if (city.value) await loadPortalDatesForCity(city.value);
        syncActionButtons();
    });

    language.addEventListener('change', async function () {
        showError(languageError, '');
        centerSection.style.display = 'none';
        center.value = '';
        center.dataset.name = '';
        resetSessionSelection();
        centerName.value = '';
        sessionName.value = '';
        sessionSnapshot = [];
        sessionSummary?.classList.add('hidden');
        centerResponse?.clear();
        sessionResponse?.clear();
        resetHold('Select a center slot and exact session before creating a temporary hold.');
        if (availableDate.value) await loadTestCentersForDate(availableDate.value);
        syncActionButtons();
    });

    availableDate.addEventListener('change', async function () {
        const value = normalizeDate(availableDate.value);
        date.value = value;
        centerSection.style.display = 'none';
        center.value = '';
        center.dataset.name = '';
        resetSessionSelection();
        centerName.value = '';
        sessionName.value = '';
        sessionSnapshot = [];
        sessionSummary?.classList.add('hidden');
        centerResponse?.clear();
        sessionResponse?.clear();
        showError(dateError, '');
        resetHold('Select a center slot and exact session for this date before creating a temporary hold.');
        availabilityCalendar?.setSelected(value, true);
        if (value) await loadTestCentersForDate(value, false);
        syncActionButtons();
    });

    center.addEventListener('change', async function () {
        const selectedCenterName = String(centerName.value || center.dataset.name || '').trim();
        center.dataset.name = selectedCenterName;
        centerName.value = selectedCenterName;
        clearSessionValue();
        sessionName.value = '';
        sessionSnapshot = [];
        sessionSummary?.classList.add('hidden');
        sessionResponse?.clear();
        resetHold('Select an exact SVP session for this date, then create a temporary hold before confirming.');
        if (center.value && availableDate.value) await loadSessionsForDate(availableDate.value);
        syncActionButtons();
    });

    session.addEventListener('change', function () {
        const option = {value: session.value, dataset: session.dataset};
        showError(centerError, '');
        showError(dateError, '');
        if (option?.dataset?.centerId && option.dataset.centerId !== String(center.value)) {
            clearSessionValue();
            sessionResponse?.syncSelection();
            resetHold('The selected session belongs to another test center and is blocked.');
            const selectedName = option.dataset.centerName || option.dataset.name || 'another test center';
            const centerLabel = centerName.value || center.dataset.name || 'the selected test center';
            showError(centerError, 'Blocked: session center "' + selectedName + '" does not match selected center "' + centerLabel + '".');
            return;
        }
        const selectedDate = normalizeDate(availableDate.value);
        const sessionDateValue = normalizeDate(option?.dataset?.date);
        if (selectedDate && sessionDateValue && selectedDate !== sessionDateValue) {
            clearSessionValue();
            sessionResponse?.syncSelection();
            resetHold('The selected session date does not match the selected available date.');
            showError(dateError, 'This session belongs to ' + sessionDateValue + '. Please select a session for ' + selectedDate + '.');
            return;
        }
        sessionName.value = option?.dataset?.name || '';
        session.dataset.name = option?.dataset?.name || session.dataset.name || '';
        date.value = selectedDate || sessionDateValue || '';
        if (session.value) holdPanel.classList.remove('hidden');
        resetHold('Create a temporary hold for this exact session and date before confirming.');
        syncActionButtons();
    });

    candidate.addEventListener('change', function () { void loadCredit(); syncActionButtons(); });
    form.addEventListener('submit', function (event) {
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

    centerSection.style.display = 'none';
    sessionSummary?.classList.add('hidden');
    loadCredit();
    loadLiveLanguages();
    loadCities();
})();
</script>
@endsection
