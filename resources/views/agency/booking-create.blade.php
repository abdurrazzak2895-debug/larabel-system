@extends('layouts.panel')

@section('title', 'New Booking')

@section('content')
<div class="max-w-3xl">
    <div class="flex items-center gap-3 mb-6">
        <a href="{{ route('agency.bookings.index') }}" class="text-slate-400 hover:text-slate-600 transition">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        </a>
        <div>
            <h2 class="text-xl font-bold text-slate-900">New Booking</h2>
            <p class="text-sm text-slate-500 mt-0.5">Complete the form below to book an exam session through SVP.</p>
        </div>
    </div>

    @if ($svpError)
        <div class="mb-6 px-4 py-3 bg-amber-50 border border-amber-200 text-amber-700 rounded-xl text-sm">
            {{ $svpError }}
            @if (! session('svp_token'))
                <a href="{{ route('svp.login.form') }}" class="ml-2 underline font-semibold">Sign in with SVP</a>
            @endif
        </div>
    @endif

    <form method="POST" action="{{ route('agency.bookings.store') }}" class="space-y-6" id="booking-form">
        @csrf

        {{-- Wallet + candidate row --}}
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="bg-white rounded-xl border border-slate-200 p-4">
                <p class="text-xs font-medium text-slate-400 uppercase tracking-wide mb-2">Wallet Balance</p>
                <p class="text-2xl font-bold text-slate-900">{{ number_format($wallet?->available_balance ?? 0, 2) }} <span class="text-sm font-medium text-slate-500">BDT</span></p>
                <p class="text-xs text-slate-400 mt-1">Reserved: {{ number_format($wallet?->reserved_balance ?? 0, 2) }} BDT</p>
            </div>
            <div class="bg-white rounded-xl border border-slate-200 p-4">
                <label for="candidate_id" class="block text-xs font-medium text-slate-400 uppercase tracking-wide mb-2">Candidate</label>
                <select name="candidate_id" id="candidate_id" required
                    class="w-full rounded-lg border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
                    <option value="">Select candidate…</option>
                    @foreach ($candidates as $c)
                        <option value="{{ $c->id }}">{{ $c->full_name ?? $c->name ?? ('Credential #'.$c->id) }}</option>
                    @endforeach
                </select>
                @error('candidate_id')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                @if ($candidates->isEmpty())
                    <p class="text-xs text-amber-600 mt-2">No candidates synced yet. Complete SVP login to auto-generate your profile as a candidate.</p>
                @endif
            </div>
        </div>

        {{-- Lookups: Occupation / City / Category --}}
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-4 space-y-4">
            <p class="text-xs font-medium text-slate-400 uppercase tracking-wide">Exam Lookups</p>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label for="occupation-search" class="block text-sm font-medium text-slate-700 mb-1">Occupation</label>
                    <div class="relative" id="occupation-combobox">
                        <input type="text" id="occupation-search" placeholder="Search occupation..." 
                            class="w-full rounded-lg border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500 pl-10 pr-9" autocomplete="off"
                            role="combobox" aria-expanded="false" aria-controls="occupation-dropdown" aria-autocomplete="list">
                        <select name="occupation_id" id="occupation_id" required
                            class="w-full rounded-lg border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500" style="display:none;" tabindex="-1" aria-hidden="true">
                            <option value="">Select…</option>
                            @php
                                $occ = data_get($occupations, 'data.occupations')
                                    ?? data_get($occupations, 'data')
                                    ?? data_get($occupations, 'occupations')
                                    ?? $occupations;
                                if (!is_array($occ)) $occ = [];
                                $occ = array_values(array_filter($occ, fn($item) => is_array($item) || is_object($item)));
                            @endphp
                            @foreach($occ as $o)
                                @php $o = (array) $o; @endphp
                                <option value="{{ $o['id'] ?? $o['occupation_id'] ?? '' }}">{{ $o['name'] ?? $o['english_name'] ?? $o['arabic_name'] ?? $o['title'] ?? $o['id'] ?? '' }}</option>
                            @endforeach
                        </select>
                        <div class="absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none text-slate-400">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M9.75 9.75c0 1.568 1.273 2.84 2.84 2.84s2.84-1.273 2.84-2.84-1.273-2.84-2.84-2.84S9.75 8.182 9.75 9.75z"/></svg>
                        </div>
                        <button type="button" id="occupation-clear" tabindex="-1" aria-label="Clear occupation selection"
                            class="hidden absolute right-2 top-1/2 -translate-y-1/2 w-5 h-5 rounded-full bg-slate-200 text-slate-500 text-xs leading-5 text-center hover:bg-slate-300">×</button>
                        <div id="occupation-dropdown" class="hidden absolute z-20 left-0 right-0 mt-1 rounded-lg border border-slate-200 bg-white shadow-lg max-h-64 overflow-y-auto">
                            <p id="occupation-dropdown-status" class="px-3 py-2 text-xs text-slate-500 border-b border-slate-100"></p>
                            <ul id="occupation-dropdown-list" class="py-1"></ul>
                        </div>
                    </div>
                    <p id="occupation-error" class="hidden text-red-600 text-xs mt-1"></p>
                    <p id="occupation-hint" class="hidden text-xs text-slate-400 mt-1">Type to search, then pick an occupation from the list.</p>
                    @error('occupation_id')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="city_id" class="block text-sm font-medium text-slate-700 mb-1">City</label>
                    <select name="city" id="city_id"
                        class="w-full rounded-lg border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
                        <option value="">Select…</option>
                    </select>
                    <p id="city-error" class="hidden text-red-600 text-xs mt-1"></p>
                </div>
                <div>
                    <label for="category_id" class="block text-sm font-medium text-slate-700 mb-1">Category</label>
                    <select name="category_id" id="category_id"
                        class="w-full rounded-lg border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
                        <option value="">Select…</option>
                        @php
                            $cats = data_get($categories, 'data.categories')
                                ?? data_get($categories, 'data')
                                ?? data_get($categories, 'categories')
                                ?? $categories;
                            if (!is_array($cats)) $cats = [];
                            $cats = array_values(array_filter($cats, fn($item) => is_array($item) || is_object($item)));
                        @endphp
                        @foreach($cats as $c)
                            @php $c = (array) $c; @endphp
                            <option value="{{ $c['id'] ?? '' }}">{{ $c['name'] ?? $c['title'] ?? $c['id'] ?? '' }}</option>
                        @endforeach
                    </select>
                    <p id="category-error" class="hidden text-red-600 text-xs mt-1"></p>
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <label for="language_code" class="block text-sm font-medium text-slate-700 mb-1">SVP exam language</label>
            <select name="language_code" id="language_code" required disabled class="w-full rounded-xl border-slate-200 bg-slate-50 text-sm focus:border-brand-500 focus:ring-brand-500">
                <option value="">Select a live SVP exam language…</option>
            </select>
            <p id="language-error" class="hidden text-red-600 text-xs mt-1"></p>
            <p class="text-xs text-slate-400 mt-1">Languages are loaded live from Portal Availability for the selected occupation. No language is preselected.</p>
        </div>

        {{-- Session, date, and live SVP payment routing --}}
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-4 space-y-4">
            <p class="text-xs font-medium text-slate-400 uppercase tracking-wide">Available Sessions — date-first PACC booking</p>
            <div class="grid grid-cols-1 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Available Exam Date</label>
                    @include('user.bookings.partials.svp-calendar', ['calendarId' => 'agency-booking-availability-calendar'])
                    <select id="available_session_date" aria-hidden="true" tabindex="-1"
                        class="hidden w-full rounded-xl border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
                        <option value="">Select a live available date first…</option>
                    </select>
                    <p class="text-xs text-slate-400 mt-1">Only dates returned live by Portal Availability for the selected city are clickable. Pick a date, then choose a center slot and one of its verified SVP sessions.</p>

                    <div id="test-center-section" style="display:none;" class="mt-3 rounded-xl border border-slate-200 bg-slate-50 p-3">
                        <div class="mb-2 flex items-center justify-between gap-3">
                            <span class="text-xs font-medium uppercase tracking-wide text-slate-500">Test Center</span>
                            <span class="text-[11px] text-slate-400">Click one card to select</span>
                        </div>
                        <input type="hidden" name="test_center_id" id="test_center_id" value="">
                        <input type="hidden" name="test_center_name" id="test_center_name" value="">
                        <p id="test-center-error" class="hidden text-red-600 text-xs mt-1"></p>
                        <p id="dhaka-center-summary" class="text-xs text-slate-400 mt-1">Select a live date to load the Portal Availability center slots for that date.</p>
                        @include('user.bookings.partials.pacc-availability-response', [
                            'componentId' => 'agency-center-response',
                            'mode' => 'centers',
                            'centerSelectId' => 'test_center_id',
                            'sessionSelectId' => 'exam_session_id',
                        ])
                    </div>

                    <input type="hidden" name="exam_session_id" id="exam_session_id" value="">
                    <input type="hidden" name="exam_session_name" id="exam_session_name" value="">
                    <input type="hidden" name="temporary_hold_id" id="temporary_hold_id" value="">
                    <input type="hidden" name="temporary_hold_expires_at" id="temporary_hold_expires_at" value="">
                    @include('user.bookings.partials.pacc-availability-response', [
                        'componentId' => 'agency-session-response',
                        'mode' => 'sessions',
                        'centerSelectId' => 'test_center_id',
                        'sessionSelectId' => 'exam_session_id',
                        'sessionNameInputId' => 'exam_session_name',
                        'dateInputId' => 'exam_date',
                    ])
                    <p id="session-center-error" class="hidden mt-2 rounded-lg border border-red-200 bg-red-50 p-2 text-xs text-red-700"></p>
                    @error('temporary_hold_id')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                    <p id="session-error" class="hidden text-red-600 text-xs mt-1"></p>
                    @error('exam_session_id')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                </div>
                <input type="hidden" name="exam_date" id="exam_date" value="">
                <p id="date-error" class="hidden text-red-600 text-xs mt-1"></p>
            </div>
            <div id="temporary-hold-panel" class="hidden rounded-lg border border-amber-200 bg-amber-50 p-4">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                    <div>
                        <p class="text-sm font-semibold text-amber-900">Temporary SVP seat hold</p>
                        <p id="temporary-hold-status" class="text-xs text-amber-800 mt-1">Select a session and date, then create a temporary hold before confirming the booking.</p>
                    </div>
                    <button type="button" id="create-temporary-hold" disabled
                        class="inline-flex items-center justify-center px-4 py-2 bg-amber-600 hover:bg-amber-700 disabled:opacity-50 disabled:cursor-not-allowed text-white text-sm font-medium rounded-lg transition">
                        Create temporary hold
                    </button>
                </div>
            </div>
            <input type="hidden" name="methodology" value="{{ config('svp.default_methodology', 'in_person') }}">
            <div id="svp-credit-panel" class="rounded-lg border border-sky-200 bg-sky-50 p-4">
                <p class="text-sm font-semibold text-sky-900">SVP reservation credit</p>
                <p id="svp-credit-status" class="text-xs text-sky-800 mt-1">Select a candidate and occupation to check the live SVP credit. If no credit is available, confirmation will open the official SVP card-payment page.</p>
                <p class="text-xs text-sky-700 mt-2">The payable amount is set by SVP after reservation creation; it is not entered in this form.</p>
            </div>
        </div>

        {{-- Actions --}}
        <div class="flex items-center gap-3">
            <button type="submit" id="confirm-booking-button" disabled class="inline-flex items-center gap-2 px-5 py-2.5 bg-brand-600 hover:bg-brand-700 disabled:opacity-50 disabled:cursor-not-allowed text-white text-sm font-medium rounded-lg transition">
                Complete booking
            </button>
            <a href="{{ route('agency.bookings.index') }}" class="inline-flex items-center gap-2 px-5 py-2.5 bg-white border border-slate-200 text-slate-700 text-sm font-medium rounded-lg hover:bg-slate-50 transition">
                Cancel
            </a>
        </div>
    </form>
</div>

<script>
    (function () {
        const candidateSelect = document.getElementById('candidate_id');
        const occupationSearchInput = document.getElementById('occupation-search');
        const occupationSelect = document.getElementById('occupation_id');
        const occupationDropdown = document.getElementById('occupation-dropdown');
        const occupationDropdownStatus = document.getElementById('occupation-dropdown-status');
        const occupationDropdownList = document.getElementById('occupation-dropdown-list');
        const occupationClear = document.getElementById('occupation-clear');
        const occupationError = document.getElementById('occupation-error');
        const occupationHint = document.getElementById('occupation-hint');
        const citySelect = document.getElementById('city_id');
        const cityError = document.getElementById('city-error');
        const categorySelect = document.getElementById('category_id');
        const categoryError = document.getElementById('category-error');
        const languageSelect = document.getElementById('language_code');
        const languageError = document.getElementById('language-error');
        const testCenterSelect = document.getElementById('test_center_id');
        const testCenterNameInput = document.getElementById('test_center_name');
        const testCenterError = document.getElementById('test-center-error');
        const dhakaCenterSummary = document.getElementById('dhaka-center-summary');
        const testCenterSection = document.getElementById('test-center-section');
        const availableDateSelect = document.getElementById('available_session_date');
        const sessionSelect = document.getElementById('exam_session_id');
        const sessionNameInput = document.getElementById('exam_session_name');
        const sessionAvailabilitySummary = document.getElementById('session-availability-summary');
        const centerResponse = window.PaccAvailabilityInstances?.['agency-center-response'];
        const sessionResponse = window.PaccAvailabilityInstances?.['agency-session-response'];
        const sessionCenterError = document.getElementById('session-center-error');
        const sessionError = document.getElementById('session-error');
        const dateInput = document.getElementById('exam_date');
        const dateError = document.getElementById('date-error');
        const temporaryHoldPanel = document.getElementById('temporary-hold-panel');
        const temporaryHoldButton = document.getElementById('create-temporary-hold');
        const temporaryHoldStatus = document.getElementById('temporary-hold-status');
        const temporaryHoldIdInput = document.getElementById('temporary_hold_id');
        const temporaryHoldExpiresInput = document.getElementById('temporary_hold_expires_at');
        const confirmBookingButton = document.getElementById('confirm-booking-button');
        const bookingForm = document.getElementById('booking-form');
        const svpCreditStatus = document.getElementById('svp-credit-status');

        // Occupation combobox state.
        let occupationsCache = [];
        let occupationsLoaded = false;
        let occupationsLoading = false;
        let temporaryHoldRequest = null;
        let creditStatusRequest = null;
        let sessionCatalog = [];
        let availableDateCatalog = [];
        let availabilityCalendar = null;
        const sessionLookupCache = new Map();
        const sessionLookupRequests = new Map();

        function selectedTestCenterLabel() {
            return String(testCenterNameInput?.value || testCenterSelect?.dataset?.name || '').trim();
        }

        function resetTestCenterSelection() {
            if (testCenterSelect) {
                testCenterSelect.value = '';
                testCenterSelect.dataset.name = '';
            }
            if (testCenterNameInput) {
                testCenterNameInput.value = '';
                delete testCenterNameInput.dataset.centerId;
            }
            centerResponse?.clear();
        }

        function clearSessionValue() {
            if (sessionSelect) {
                sessionSelect.value = '';
                sessionSelect.dataset.name = '';
                sessionSelect.dataset.date = '';
                sessionSelect.dataset.centerId = '';
            }
            if (sessionNameInput) sessionNameInput.value = '';
        }

        function resetSessionSelection() {
            clearSessionValue();
            sessionResponse?.clear();
        }

        function mountAvailabilityCalendar() {
            if (!window.SvpCalendar || availabilityCalendar) return;
            availabilityCalendar = window.SvpCalendar.create('agency-booking-availability-calendar', {
                emptyText: 'Pick a city to load live open exam dates.',
                onSelect: function (date) {
                    if (!date || date === availableDateSelect.value) return;
                    availableDateSelect.value = date;
                    availableDateSelect.dispatchEvent(new Event('change'));
                }
            });
        }
        mountAvailabilityCalendar();

        async function loadLiveLanguages(occupationId) {
            if (!languageSelect) return;
            languageSelect.innerHTML = '<option value="">Select a live SVP exam language…</option>';
            languageSelect.value = '';
            languageSelect.disabled = true;
            clearError(languageError);
            if (!occupationId) return;

            try {
                setLoading(languageSelect, true);
                const data = await fetchJSON("{{ route('agency.bookings.lookup.languages') }}?occupation_id=" + encodeURIComponent(occupationId));
                const languages = data?.data?.languages || data?.languages || [];
                languages.forEach(function (language) {
                    const code = String(language?.code || '').trim();
                    const name = String(language?.name || language?.english_name || code).trim();
                    if (!code || !name) return;
                    const option = document.createElement('option');
                    option.value = code;
                    option.textContent = name + ' (' + code + ')';
                    languageSelect.appendChild(option);
                });
                languageSelect.disabled = languages.length === 0;
                if (languages.length === 0) showError(languageError, 'No live exam languages returned for this occupation.');
            } catch (error) {
                showError(languageError, 'Could not load live exam languages. Please try again.');
                console.error(error);
            } finally {
                setLoading(languageSelect, false);
            }
        }

        async function loadCreditStatus() {
            const candidateId = candidateSelect?.value;
            const occupationId = occupationSelect?.value;
            if (!candidateId || !occupationId) {
                if (svpCreditStatus) svpCreditStatus.textContent = 'Select a candidate and occupation to check the live SVP credit. If no credit is available, confirmation will open the official SVP card-payment page.';
                return;
            }
            if (creditStatusRequest) return;
            if (svpCreditStatus) svpCreditStatus.textContent = 'Checking the live SVP reservation credit…';
            const params = new URLSearchParams({ candidate_id: candidateId, occupation_id: occupationId, methodology: document.querySelector('[name="methodology"]')?.value || 'in_person' });
            creditStatusRequest = fetchJSON("{{ route('agency.bookings.credit-status') }}?" + params.toString())
                .then(data => {
                    const credits = Number(data?.data?.credits ?? 0);
                    if (svpCreditStatus) svpCreditStatus.textContent = credits > 0
                        ? 'SVP reports ' + credits + ' reservation credit' + (credits === 1 ? '' : 's') + '. Confirming will use one credit; no card-payment page will open.'
                        : 'No SVP reservation credit is available for this occupation. Confirming after the hold will open the official SVP card-payment page.';
                })
                .catch(error => {
                    if (svpCreditStatus) svpCreditStatus.textContent = 'SVP credit status could not be loaded. Please refresh the SVP login before confirming.';
                    console.error(error);
                })
                .finally(() => { creditStatusRequest = null; });
            await creditStatusRequest;
        }

        function formatHoldExpiry(value) {
            if (!value) return '';
            const parsed = new Date(value);
            return Number.isNaN(parsed.getTime()) ? String(value) : parsed.toLocaleString();
        }

        function escapeHtml(value) {
            return String(value ?? '').replace(/[&<>\"']/g, function (character) {
                return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '\"': '&quot;', "'": '&#039;' })[character];
            });
        }

        function sessionDate(session) {
            return String(session?.exam_date || session?.test_date || session?.date || session?.start_date_in_browser_time_zone || session?.start_date_in_tc_time_zone || '').substring(0, 10);
        }

        function sessionCenterId(session) {
            return String(session?.test_center_id ?? session?.site_id ?? session?.center_id ?? session?.test_center?.id ?? session?.site?.id ?? session?.center?.id ?? '');
        }

        function sessionCenterName(session) {
            const explicit = session?.test_center_name || session?.site_name || session?.center_name || session?.test_center?.name || session?.site?.name || session?.center?.name;
            if (explicit) return explicit;
            // This SVP deployment's session list omits test_center.id/name
            // entirely (only city/country come back). The list is already
            // scoped to the center the user selected, so fall back to that
            // known name instead of showing "Unknown center" for every row.
                const selectedName = selectedTestCenterLabel();
            return (selectedName && selectedName.trim()) || 'Unknown center';
        }

        function normalizeAvailableDate(item) {
            const date = typeof item === 'string' ? item : item?.exam_date || item?.date || item?.test_date || '';
            return String(date).substring(0, 10);
        }

        function renderAvailableDates(sessions, availableDates) {
            if (!availableDateSelect) return [];
            const selectedCenterId = String(testCenterSelect?.value || '');
            const dates = new Set();
            (availableDates || []).forEach(function (item) {
                const itemCenterId = typeof item === 'object' ? String(item?.test_center_id ?? item?.test_center?.id ?? item?.site_id ?? '') : '';
                const date = normalizeAvailableDate(item);
                if (date && (!itemCenterId || itemCenterId === selectedCenterId)) dates.add(date);
            });
            (sessions || []).forEach(function (session) {
                const centerId = sessionCenterId(session);
                const date = sessionDate(session);
                if (date && (!centerId || centerId === selectedCenterId)) dates.add(date);
            });
            const sortedDates = Array.from(dates).filter(date => /^\d{4}-\d{2}-\d{2}$/.test(date)).sort();
            availableDateSelect.innerHTML = '<option value="">Select an available date…</option>';
            sortedDates.forEach(function (date) {
                const option = document.createElement('option');
                option.value = date;
                option.textContent = date;
                availableDateSelect.appendChild(option);
            });
            availableDateSelect.disabled = sortedDates.length === 0;
            availabilityCalendar?.setDates(sortedDates);
            availabilityCalendar?.setSelected(availableDateSelect.value, true);
            return sortedDates;
        }

        function mergeSessionCatalog(items) {
            const merged = new Map((sessionCatalog || []).map(session => [String(session?.id || session?.exam_session_id || sessionDate(session) + '|' + session?.name), session]));
            (items || []).forEach(function (session) {
                const key = String(session?.id || session?.exam_session_id || sessionDate(session) + '|' + session?.name);
                if (key) merged.set(key, session);
            });
            sessionCatalog = Array.from(merged.values());
        }

    function renderSessionsForDate(date) {
        const selectedDate = String(date || '').substring(0, 10);
        const filtered = selectedDate
            ? (sessionCatalog || []).filter(session => sessionDate(session) === selectedDate)
            : [];
        if (selectedDate) {
            sessionResponse?.renderSessions(filtered, {
                date: selectedDate,
                emptyText: 'No exact SVP sessions returned for the selected center and date.'
            });
        } else {
            sessionResponse?.clear();
        }
        if (sessionSelect) {
            sessionSelect.value = '';
            sessionSelect.dataset.name = '';
            sessionSelect.dataset.date = '';
            sessionSelect.dataset.centerId = '';
        }
        return filtered;
    }

        function sessionLookupKey(centerId, dateValue) {
            return [citySelect.value, categorySelect.value, centerId, dateValue].join('|');
        }

        function sessionRowsFromResponse(body) {
            return body?.data?.sessions || body?.data?.exam_sessions || body?.sessions || body?.exam_sessions || [];
        }

        function requestSessionsForCenter(centerId, dateValue) {
            const key = sessionLookupKey(centerId, dateValue);
            if (sessionLookupCache.has(key)) return Promise.resolve(sessionLookupCache.get(key));
            if (sessionLookupRequests.has(key)) return sessionLookupRequests.get(key);
            const params = new URLSearchParams({city: citySelect.value, category_id: categorySelect.value, test_center_id: centerId, exam_date: dateValue});
            const request = fetchJSON("{{ route('agency.bookings.lookup.sessions') }}?" + params.toString())
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

        async function loadSessionsForDate(date) {
            if (!date || !citySelect.value || !categorySelect.value || !testCenterSelect.value) {
                renderSessionsForDate(date);
                return;
            }
            const requestedCenterId = String(testCenterSelect.value);
            try {
                setLoading(sessionSelect, true);
                const sessions = await requestSessionsForCenter(requestedCenterId, date);
                if (requestedCenterId !== String(testCenterSelect.value) || date !== availableDateSelect?.value) return;
            sessionCatalog = Array.isArray(sessions) ? sessions.slice() : [];
            renderAvailableDates(sessionCatalog, availableDateCatalog);
                availableDateSelect.value = date;
                dateInput.value = date;
                renderSessionsForDate(date);
                if (!sessions.length) showError(sessionError, 'No sessions returned for this date at the selected center.');
            } catch (error) {
                if (requestedCenterId !== String(testCenterSelect.value) || date !== availableDateSelect?.value) return;
                sessionResponse?.renderSessions([], {date: date, emptyText: error.message});
                clearSessionValue();
                showError(sessionError, error.message);
                console.error(error);
            } finally {
                setLoading(sessionSelect, false);
            }
        }

        function sessionTime(session) {
            const value = session?.test_time || session?.start_time || session?.time || session?.start_at || session?.start_date_in_browser_time_zone || session?.start_date_in_tc_time_zone || '';
            return String(value).replace(/^\d{4}-\d{2}-\d{2}[T ]/, '').trim();
        }

        function sessionSeatCount(session) {
            const value = session?.available_seats ?? session?.availableSeats ?? session?.remaining_seats ?? session?.remainingSeats ?? session?.seats ?? null;
            if (value === null || value === '' || Number.isNaN(Number(value))) return null;
            return Number(value);
        }

        function sessionDisplayName(session, index) {
            const value = String(session?.session_name || session?.name || session?.label || session?.title || '').trim();
            return value || 'Session ' + (index + 1);
        }

        function sessionOptionLabel(session, index) {
            const details = [sessionDisplayName(session, index)];
            const time = sessionTime(session);
            const seats = sessionSeatCount(session);
            if (time) details.push('Time: ' + time);
            details.push(seats === null ? 'Live seats unavailable' : 'Seats: ' + seats);
            return details.join(' · ');
        }
        function clearSessionCenterError() {
            if (!sessionCenterError) return;
            sessionCenterError.textContent = '';
            sessionCenterError.classList.add('hidden');
        }

        function clearTemporaryHold(message) {
            if (temporaryHoldIdInput) temporaryHoldIdInput.value = '';
            if (temporaryHoldExpiresInput) temporaryHoldExpiresInput.value = '';
            if (confirmBookingButton) confirmBookingButton.disabled = true;
            if (temporaryHoldButton) temporaryHoldButton.disabled = true;
            if (temporaryHoldStatus && message) temporaryHoldStatus.textContent = message;
            if (temporaryHoldPanel) temporaryHoldPanel.classList.toggle('hidden', !sessionSelect?.value);
        }

        async function createTemporaryHold() {
            if (temporaryHoldRequest) return;
            const selectedSessionOption = {value: sessionSelect.value, dataset: sessionSelect.dataset};
            if (selectedSessionOption?.dataset?.centerId && selectedSessionOption.dataset.centerId !== String(testCenterSelect.value)) {
                clearTemporaryHold('The selected session belongs to another test center and is blocked.');
                if (sessionCenterError) {
                    const selectedSessionCenterName = selectedSessionOption.dataset.centerName || selectedSessionOption.dataset.name || 'another test center';
                    const selectedCenterName = selectedTestCenterLabel() || 'the selected test center';
                    sessionCenterError.textContent = 'Blocked: session center "' + selectedSessionCenterName + '" does not match selected center "' + selectedCenterName + '".';
                    sessionCenterError.classList.remove('hidden');
                }
                return;
            }
            const payload = {
                occupation_id: occupationSelect.value,
                category_id: categorySelect.value,
                city: citySelect.value,
                test_center_id: testCenterSelect.value,
                test_center_name: testCenterNameInput.value,
                exam_session_id: sessionSelect.value,
                exam_date: dateInput.value
            };
            if (Object.values(payload).some(value => !value)) {
                if (temporaryHoldStatus) temporaryHoldStatus.textContent = 'Select occupation, category, city, center, session, and date first.';
                return;
            }
            temporaryHoldButton.disabled = true;
            temporaryHoldStatus.textContent = 'Verifying the live SVP session center…';
            const verifyUrl = new URL("{{ route('agency.bookings.lookup.verify-session-center') }}", window.location.origin);
            verifyUrl.searchParams.set('exam_session_id', payload.exam_session_id);
            verifyUrl.searchParams.set('expected_test_center_id', payload.test_center_id);
            verifyUrl.searchParams.set('expected_test_center_name', payload.test_center_name);
            verifyUrl.searchParams.set('expected_city', payload.city);
            verifyUrl.searchParams.set('expected_exam_date', payload.exam_date);
            temporaryHoldRequest = Promise.resolve().then(async function () {
                const verification = await fetchJSON(verifyUrl.toString());
                if (!verification.verified) {
                    const actualName = verification.actual?.test_center_name || 'unknown center';
                    const selectedName = selectedTestCenterLabel() || 'the selected test center';
                    const message = 'Blocked before hold: live SVP session belongs to "' + actualName + '" instead of "' + selectedName + '" or its date/metadata is not valid.';
                    if (sessionCenterError) {
                        sessionCenterError.textContent = message;
                        sessionCenterError.classList.remove('hidden');
                    }
                    throw new Error(message);
                }
                temporaryHoldStatus.textContent = 'Creating the live SVP temporary hold…';
                return fetch("{{ route('agency.bookings.temporary-hold') }}", {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                },
                    body: JSON.stringify(payload)
                });
            }).then(async response => {
                const body = await response.json().catch(() => ({}));
                if (!response.ok || body.success === false) {
                    const error = new Error(body.error || 'SVP could not create the temporary hold.');
                    if (body.requires_svp_login && body.login_url) error.loginUrl = body.login_url;
                    throw error;
                }
                const resolved = body.selection || {};
                if (resolved.exam_session_id && resolved.exam_session_id !== sessionSelect.value) {
                    sessionSelect.value = resolved.exam_session_id;
                    sessionSelect.dispatchEvent(new Event('change'));
                    temporaryHoldStatus.textContent = 'PACC matched the next available date at the selected center: ' + (resolved.exam_date || dateInput.value) + '. Creating the hold…';
                }
                if (resolved.exam_date && resolved.exam_date !== dateInput.value) {
                    dateInput.value = resolved.exam_date;
                }
                const hold = body.data || body;
                const holdId = hold.id ?? hold.hold_id ?? hold.temporary_hold_id;
                if (!holdId) throw new Error('SVP returned no temporary hold ID.');
                temporaryHoldIdInput.value = holdId;
                temporaryHoldExpiresInput.value = hold.expired_at || hold.expires_at || '';
                temporaryHoldStatus.classList.remove('text-red-700');
                confirmBookingButton.disabled = false;
                temporaryHoldStatus.textContent = 'Hold #' + holdId + ' created' + (temporaryHoldExpiresInput.value ? ' — expires ' + formatHoldExpiry(temporaryHoldExpiresInput.value) : '.') + ' You may now confirm the booking.';
            }).catch(error => {
                if (error.loginUrl && temporaryHoldStatus) {
                    clearTemporaryHold('');
                    temporaryHoldStatus.innerHTML = escapeHtml(error.message) + ' <a class="underline font-semibold" href="' + escapeHtml(error.loginUrl) + '">Sign in with SVP again</a>.';
                    temporaryHoldStatus.classList.add('text-red-700');
                } else {
                    clearTemporaryHold(error.message);
                    if (temporaryHoldStatus) temporaryHoldStatus.classList.add('text-red-700');
                }
            }).finally(() => {
                temporaryHoldRequest = null;
                if (!temporaryHoldIdInput.value && occupationSelect.value && categorySelect.value && citySelect.value && testCenterSelect.value && sessionSelect.value && dateInput.value) temporaryHoldButton.disabled = false;
            });
            await temporaryHoldRequest;
        }

        temporaryHoldButton?.addEventListener('click', createTemporaryHold);
        bookingForm?.addEventListener('submit', function (event) {
            if (temporaryHoldIdInput?.value) return;

            event.preventDefault();
            clearTemporaryHold('Create a live SVP temporary hold before confirming the booking.');
            temporaryHoldPanel?.classList.remove('hidden');
        });

        function showError(el, message) {
            if (!el) return;
            el.textContent = message;
            el.classList.remove('hidden');
        }

        function clearError(el) {
            if (!el) return;
            el.textContent = '';
            el.classList.add('hidden');
        }

        function setLoading(select, isLoading) {
            if (!select) return;
            select.disabled = isLoading;
            const parent = select.parentElement;
            if (!parent) return;
            parent.querySelectorAll('.svp-loading-indicator').forEach(function (loading) {
                loading.remove();
            });
            if (isLoading) {
                const loading = document.createElement('span');
                loading.className = 'svp-loading-indicator text-xs text-slate-400 ml-2';
                loading.textContent = 'Loading…';
                select.insertAdjacentElement('afterend', loading);
            }
        }

        function populateSelect(select, items, valueKey, labelKey) {
            if (!select) return;
            const current = select.value;
            select.innerHTML = '<option value="">Select…</option>';
            const sessionDateCounts = {};
            (items || []).forEach(function (item) {
                const option = document.createElement('option');
                const value = item[valueKey] ?? '';
                const baseLabel = item[labelKey] || value || '';
                const centerId = item.test_center_id ?? item.site_id ?? null;
                const centerName = item.test_center_name ?? item.site_name ?? item.center_name ?? item.test_center?.name ?? item.site?.name ?? item.center?.name;
                option.value = value;
                option.dataset.name = item.name || baseLabel;
                option.dataset.centerName = centerName || '';
                option.dataset.centerId = centerId || '';
                option.dataset.date = item.exam_date || item.test_date || item.date || item.start_date_in_browser_time_zone || item.start_date_in_tc_time_zone || '';
            if (select === testCenterSelect && centerId) {
                const centerDetails = [centerName || baseLabel];
                const centerTime = sessionTime(item);
                const centerSeats = sessionSeatCount(item);
                if (centerTime) centerDetails.push('Time: ' + centerTime);
                if (centerSeats !== null) centerDetails.push('Seats: ' + centerSeats);
                option.textContent = centerDetails.join(' · ');
            } else if (select === sessionSelect) {
                    const date = option.dataset.date || 'Unknown date';
                    const sameDateIndex = sessionDateCounts[date] || 0;
                    sessionDateCounts[date] = sameDateIndex + 1;
                    // This SVP deployment's session list omits test_center.id
                    // entirely (only city/country come back) — centerId is ''
                    // for essentially every row. Treat a centerless row as
                    // trusted-scoped to the requested center (the list itself
                    // was fetched filtered by test_center_id), matching
                    // resolveCenterSession's/verifyForHold's fallback on the
                    // backend. Only disable an option when it carries an
                    // EXPLICIT, different center id.
                option.textContent = date + ' — ' + sessionOptionLabel(item, sameDateIndex);
                    option.disabled = !!(centerId && testCenterSelect?.value && String(centerId) !== String(testCenterSelect.value));
                } else {
                    option.textContent = baseLabel;
                }
                select.appendChild(option);
            });
            if (current) {
                select.value = current;
            }
        }

        async function fetchJSON(url) {
            const response = await fetch(url, { headers: { 'Accept': 'application/json' } });
            const body = await response.json().catch(() => ({}));
            if (response.status === 401) {
                const loginUrl = body.login_url || "{{ route('svp.login.form', ['force' => 1]) }}";
                window.location.assign(loginUrl);
                throw new Error(body.error || 'Your SVP session has expired. Please sign in again.');
            }
            if (!response.ok) {
                throw new Error('HTTP ' + response.status + ': ' + (body.error || body.message || 'SVP lookup failed.'));
            }
            return body;
        }

        function normalizeOccupationRecord(item) {
            if (!item || typeof item !== 'object') return null;
            const id = String(item.id ?? item.occupation_id ?? '').trim();
            const name = String(item.name ?? item.english_name ?? item.arabic_name ?? item.title ?? id).trim();
            return id && name ? { id: id, name: name } : null;
        }

        function mergeOccupationRecords(items) {
            const merged = new Map((occupationsCache || []).map(function (occupation) {
                return [String(occupation.id), occupation];
            }));
            (Array.isArray(items) ? items : []).forEach(function (item) {
                const occupation = normalizeOccupationRecord(item);
                if (!occupation) return;
                const existing = merged.get(occupation.id) || {};
                merged.set(occupation.id, { ...existing, ...occupation });
            });
            occupationsCache = Array.from(merged.values());
        }

        // Seed the cache from the options the server already rendered. The SVP
        // search endpoint can ignore its search parameter or return only a
        // partial page, so these known occupations must remain available.
        function seedOccupationsFromServer() {
            if (!occupationSelect) return;
            const seeded = [];
            occupationSelect.querySelectorAll('option').forEach(function (opt) {
                const name = opt.textContent.trim();
                if (opt.value && name && name.toLowerCase() !== 'load' && name.toLowerCase() !== 'loading') {
                    seeded.push({ id: opt.value, name: name });
                }
            });
            mergeOccupationRecords(seeded);
            occupationsLoaded = occupationsCache.length > 0;
        }

        function syncOccupationSearchDisplay() {
            if (!occupationSelect || !occupationSearchInput) return;
            const option = occupationSelect.options[occupationSelect.selectedIndex];
            const label = option ? option.textContent.trim() : '';
            const validLabel = label && label.toLowerCase() !== 'load' && label.toLowerCase() !== 'loading';
            occupationSearchInput.value = occupationSelect.value && validLabel ? label : '';
            occupationClear?.classList.toggle('hidden', !occupationSelect.value || !validLabel);
        }

        async function loadOccupationsFromApi(searchTerm) {
            if (occupationsLoading) return;
            // Preserve the complete server-rendered list before querying SVP.
            // SVP may ignore `search` and return a different page, which must
            // not make a valid occupation disappear from the dropdown.
            seedOccupationsFromServer();
            occupationsLoading = true;
            try {
                const url = "{{ route('agency.bookings.lookup.occupations') }}?page=1" + (searchTerm ? '&search=' + encodeURIComponent(searchTerm) : '');
                const data = await fetchJSON(url);
                const occupations = data.data?.occupations || data.data || data.occupations || [];
                mergeOccupationRecords(occupations);
                occupationsLoaded = occupationsCache.length > 0;
                clearError(occupationError);
                return true;
            } catch (e) {
                // The seeded list is still usable when the optional live search
                // request fails, so only show the error if no occupation exists.
                if (occupationsCache.length === 0) {
                    showError(occupationError, 'Could not load occupations. The SVP service is unreachable — please try again in a moment.');
                }
                console.error(e);
                return occupationsCache.length > 0;
            } finally {
                occupationsLoading = false;
            }
        }

        function renderOccupationDropdown(filter) {
            if (!occupationDropdown || !occupationDropdownStatus || !occupationDropdownList) return;
            const term = (filter || '').trim().toLowerCase();
            const matches = occupationsCache.filter(function (o) {
                return !term || (o.name || '').toLowerCase().indexOf(term) !== -1;
            });

            if (occupationsCache.length === 0) {
                occupationDropdownStatus.textContent = 'No occupations available.';
            } else if (matches.length === 0) {
                occupationDropdownStatus.textContent = 'No occupations match "' + (filter || '') + '".';
            } else {
                occupationDropdownStatus.textContent = matches.length + (matches.length === 1 ? ' occupation' : ' occupations');
            }

            occupationDropdownList.innerHTML = '';
            matches.slice(0, 100).forEach(function (o) {
                const li = document.createElement('li');
                li.className = 'px-3 py-2 text-sm text-slate-700 cursor-pointer hover:bg-brand-50';
                li.textContent = o.name;
                li.addEventListener('click', function () {
                    selectOccupation(o.id, o.name);
                });
                occupationDropdownList.appendChild(li);
            });

            occupationDropdown.classList.remove('hidden');
            occupationSearchInput.setAttribute('aria-expanded', 'true');
        }

        function selectOccupation(id, name) {
            if (!id) return;
            occupationSelect.value = id;
            occupationSearchInput.value = name;
            occupationDropdown.classList.add('hidden');
            occupationSearchInput.setAttribute('aria-expanded', 'false');
            occupationClear.classList.remove('hidden');
            occupationHint.classList.add('hidden');
            clearError(occupationError);
            occupationSelect.dispatchEvent(new Event('change'));
        }

        function clearOccupation() {
            occupationSelect.value = '';
            occupationSearchInput.value = '';
            occupationDropdown.classList.add('hidden');
            occupationSearchInput.setAttribute('aria-expanded', 'false');
            occupationClear.classList.add('hidden');
            occupationHint.classList.remove('hidden');
            clearError(occupationError);
            occupationSelect.dispatchEvent(new Event('change'));
        }

        async function ensureOccupationsLoaded(searchTerm) {
            const term = (searchTerm || '').trim();
            if (term) {
                // A real search term should always query the live SVP API —
                // the initially-seeded list may only be the first page/batch
                // and won't contain every occupation.
                await loadOccupationsFromApi(term);
                return;
            }
            seedOccupationsFromServer();
            if (!occupationsLoaded) {
                await loadOccupationsFromApi('');
            }
        }

        if (occupationSearchInput) {
            let debounceTimer;

            occupationSearchInput.addEventListener('focus', function () {
                ensureOccupationsLoaded(occupationSearchInput.value).then(function () {
                    renderOccupationDropdown(occupationSearchInput.value);
                });
            });

            occupationSearchInput.addEventListener('input', function () {
                clearTimeout(debounceTimer);
                clearError(occupationError);
                debounceTimer = setTimeout(function () {
                    ensureOccupationsLoaded(occupationSearchInput.value).then(function () {
                        renderOccupationDropdown(occupationSearchInput.value);
                    });
                }, 300);
            });

            occupationSearchInput.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    ensureOccupationsLoaded(occupationSearchInput.value).then(function () {
                        renderOccupationDropdown(occupationSearchInput.value);
                    });
                }
                if (e.key === 'Escape') {
                    occupationDropdown.classList.add('hidden');
                    occupationSearchInput.setAttribute('aria-expanded', 'false');
                }
            });

            document.addEventListener('click', function (e) {
                if (!e.target.closest('#occupation-combobox') && !e.target.closest('#occupation-dropdown')) {
                    occupationDropdown.classList.add('hidden');
                    occupationSearchInput.setAttribute('aria-expanded', 'false');
                }
            });
        }

        if (occupationClear) {
            occupationClear.addEventListener('click', clearOccupation);
        }

        if (occupationSelect) {
            syncOccupationSearchDisplay();
            occupationSelect.addEventListener('change', async function () {
                syncOccupationSearchDisplay();
                const occupationId = occupationSelect.value;
                testCenterSection.style.display = 'none';
                populateSelect(citySelect, []);
                populateSelect(categorySelect, []);
                resetTestCenterSelection();
                sessionCatalog = [];
                availableDateCatalog = [];
                renderAvailableDates([], []);
                resetSessionSelection();
                renderSessionsForDate('');
                if (testCenterNameInput) testCenterNameInput.value = '';
                if (sessionNameInput) sessionNameInput.value = '';
                dateInput.value = '';
                clearTemporaryHold('Select a session and date, then create a temporary hold before confirming the booking.');
                clearError(cityError);
                clearError(categoryError);
                clearError(languageError);
                if (languageSelect) {
                    languageSelect.innerHTML = '<option value="">Select a live SVP exam language…</option>';
                    languageSelect.value = '';
                    languageSelect.disabled = true;
                }
                clearError(testCenterError);
                clearError(sessionError);
                clearError(dateError);

                if (!occupationId) {
                    return;
                }

                void loadCreditStatus();
                void loadLiveLanguages(occupationId);

                try {
                    setLoading(categorySelect, true);
                    const catData = await fetchJSON("{{ route('agency.bookings.lookup.categories') }}?occupation_id=" + encodeURIComponent(occupationId));
                    const categories = (catData && Array.isArray(catData.data)) ? catData.data : [];
                    populateSelect(categorySelect, categories, 'id', 'name');
                    if (categories.length === 1) {
                        categorySelect.value = String(categories[0].id ?? categories[0].category_id ?? '');
                        categorySelect.dispatchEvent(new Event('change'));
                    }
                    if (categories.length === 0) {
                        showError(categoryError, 'No categories available for this occupation.');
                    }
                } catch (e) {
                    showError(categoryError, 'Could not load categories. The SVP service is unreachable — please try again.');
                    console.error(e);
                } finally {
                    setLoading(categorySelect, false);
                }
            });
        }

        candidateSelect?.addEventListener('change', () => { void loadCreditStatus(); });

        if (categorySelect) {
            categorySelect.addEventListener('change', async function () {
                const categoryId = categorySelect.value;
                testCenterSection.style.display = 'none';
                populateSelect(citySelect, []);
                resetTestCenterSelection();
                sessionCatalog = [];
                availableDateCatalog = [];
                renderAvailableDates([], []);
                resetSessionSelection();
                renderSessionsForDate('');
                if (testCenterNameInput) testCenterNameInput.value = '';
                if (sessionNameInput) sessionNameInput.value = '';
                dateInput.value = '';
                clearTemporaryHold('Select a session and date, then create a temporary hold before confirming the booking.');
                clearError(cityError);
                clearError(testCenterError);
                clearError(sessionError);
                clearError(dateError);

                if (!categoryId) {
                    return;
                }

                try {
                    setLoading(citySelect, true);
                    const cityData = await fetchJSON("{{ route('agency.bookings.lookup.cities') }}?category_id=" + encodeURIComponent(categoryId));
                    const cities = (cityData && Array.isArray(cityData.data)) ? cityData.data : [];
                    populateSelect(citySelect, cities, 'name', 'name');
                    if (cities.length === 0) {
                        showError(cityError, 'No cities available for this category.');
                    }
                } catch (e) {
                    showError(cityError, 'Could not load cities. The SVP service is unreachable — please try again.');
                    console.error(e);
                } finally {
                    setLoading(citySelect, false);
                }
            });
        }

        languageSelect?.addEventListener('change', function () {
            if (languageSelect.value && citySelect.value && categorySelect.value) {
                citySelect.dispatchEvent(new Event('change'));
            } else {
                testCenterSection.style.display = 'none';
                resetTestCenterSelection();
                resetSessionSelection();
                renderSessionsForDate('');
                renderAvailableDates([], []);
                dateInput.value = '';
                clearTemporaryHold('Select a live exam language and city to load verified test centers.');
            }
        });

        async function loadPortalDatesForCity(city) {
            if (!city || !categorySelect.value) return;
            try {
                setLoading(availableDateSelect, true);
                const url = "{{ route('agency.bookings.lookup.dates') }}?city=" + encodeURIComponent(city) + "&category_id=" + encodeURIComponent(categorySelect.value);
                const data = await fetchJSON(url);
                const availableDates = data?.data?.dates || data?.dates || [];
                sessionCatalog = [];
                availableDateCatalog = Array.isArray(availableDates) ? availableDates : [];
                const dates = renderAvailableDates([], availableDateCatalog);
                availableDateSelect.value = '';
                availabilityCalendar?.setSelected('', true);
                dateInput.value = '';
                renderSessionsForDate('');
                if (dates.length) {
                    if (temporaryHoldStatus) temporaryHoldStatus.textContent = dates.length + ' live exam date' + (dates.length === 1 ? '' : 's') + ' available for ' + city + '. Select a date to load its center slots.';
                } else {
                    showError(dateError, 'No live Portal Availability dates returned for ' + city + '.');
                }
            } catch (e) {
                availableDateCatalog = [];
                renderAvailableDates([], []);
                renderSessionsForDate('');
                showError(dateError, 'Could not load live dates. The SVP service is unreachable — please try again.');
                console.error(e);
            } finally {
                setLoading(availableDateSelect, false);
            }
        }

        async function loadTestCentersForDate(date) {
            const city = citySelect.value;
            const categoryId = categorySelect.value;
            const occupationId = occupationSelect.value;
            const languageCode = languageSelect?.value || '';
            if (!date || !city || !categoryId || !occupationId || !languageCode) return;

            try {
                setLoading(testCenterSelect, true);
                const url = "{{ route('agency.bookings.lookup.test-centers') }}?city=" + encodeURIComponent(city) + "&category_id=" + encodeURIComponent(categoryId) + "&date=" + encodeURIComponent(date) + "&occupation_id=" + encodeURIComponent(occupationId) + "&language_code=" + encodeURIComponent(languageCode);
                const data = await fetchJSON(url);
                const centers = data?.data?.test_centers || (data && Array.isArray(data.data) ? data.data : []);
                centerResponse?.renderCenters(centers, {
                    city: city,
                    date: date,
                    emptyText: 'No live test-center slots returned for the selected date.'
                });
                testCenterSelect.value = '';
                testCenterSelect.dataset.name = '';
                if (dhakaCenterSummary) {
                    dhakaCenterSummary.textContent = centers.length
                        ? 'Portal Availability returned ' + centers.length + ' center slot' + (centers.length === 1 ? '' : 's') + ' for ' + city + ' on ' + date + '. Select one to load exact SVP sessions.'
                        : 'No center slots returned for ' + city + ' on ' + date + '.';
                }
                testCenterSection.style.display = centers.length ? '' : 'none';
                prefetchSessionsForCenters(centers, date);
                if (!centers.length) showError(testCenterError, 'No test centers available for the selected date.');
            } catch (e) {
                testCenterSection.style.display = 'none';
                showError(testCenterError, 'Could not load test centers. The SVP service is unreachable — please try again.');
                console.error(e);
            } finally {
                setLoading(testCenterSelect, false);
            }
        }

        if (citySelect) {
            citySelect.addEventListener('change', async function () {
                const city = citySelect.value;
                testCenterSection.style.display = 'none';
                resetTestCenterSelection();
                sessionCatalog = [];
                availableDateCatalog = [];
                renderAvailableDates([], []);
                resetSessionSelection();
                renderSessionsForDate('');
                if (testCenterNameInput) testCenterNameInput.value = '';
                if (sessionNameInput) sessionNameInput.value = '';
                dateInput.value = '';
                clearTemporaryHold('Select a live date, center, and session before creating a temporary hold.');
                clearError(testCenterError);
                clearError(sessionError);
                clearError(dateError);

                if (city && categorySelect.value) {
                    await loadPortalDatesForCity(city);
                }
            });
        }

        if (testCenterSelect) {
            testCenterSelect.addEventListener('change', async function () {
                const testCenterId = testCenterSelect.value;
                const selectedCenterName = selectedTestCenterLabel();
                const date = availableDateSelect?.value || '';
                if (sessionNameInput) sessionNameInput.value = '';
                sessionCatalog = [];
                resetSessionSelection();
                renderSessionsForDate('');
                dateInput.value = date || '';
                clearTemporaryHold('Select a session for this date, then create a temporary hold before confirming the booking.');
                clearError(sessionError);
                clearError(dateError);

                if (!testCenterId || !date || !citySelect.value || !categorySelect.value) return;
                await loadSessionsForDate(date);
            });
        }

        if (availableDateSelect) {
            availableDateSelect.addEventListener('change', async function () {
                const date = availableDateSelect.value;
                dateInput.value = date || '';
                clearError(dateError);
                clearTemporaryHold('Select a center slot and session for this date, then create a temporary hold before confirming the booking.');
                resetTestCenterSelection();
                if (testCenterNameInput) testCenterNameInput.value = '';
                if (sessionNameInput) sessionNameInput.value = '';
                sessionCatalog = [];
                renderSessionsForDate('');
                testCenterSection.style.display = 'none';
                if (!date) return;
                await loadTestCentersForDate(date);
            });
        }

        if (sessionSelect) {
            sessionSelect.addEventListener('change', function () {
                const sessionId = sessionSelect.value;
                const selectedSessionOption = {value: sessionSelect.value, dataset: sessionSelect.dataset};
                clearSessionCenterError();
                if (selectedSessionOption?.dataset?.centerId && selectedSessionOption.dataset.centerId !== String(testCenterSelect.value)) {
                    clearSessionValue();
                    sessionResponse?.syncSelection();
                    clearTemporaryHold('The selected session belongs to another center and has been blocked.');
                    if (sessionCenterError) {
                        const selectedSessionCenterName = selectedSessionOption.dataset.centerName || selectedSessionOption.dataset.name || 'another test center';
                        const selectedCenterName = selectedTestCenterLabel() || 'the selected test center';
                        sessionCenterError.textContent = 'Blocked: selected session center "' + selectedSessionCenterName + '" does not match selected center "' + selectedCenterName + '".';
                        sessionCenterError.classList.remove('hidden');
                    }
                    return;
                }
                if (sessionNameInput) sessionNameInput.value = selectedSessionOption?.dataset?.name || '';
                sessionSelect.dataset.name = selectedSessionOption?.dataset?.name || sessionSelect.dataset.name || '';

                const selectedDate = availableDateSelect?.value || '';
                const sessionDateValue = (selectedSessionOption?.dataset?.date || '').substring(0, 10);
                if (selectedDate && sessionDateValue && selectedDate !== sessionDateValue) {
                    clearSessionValue();
                    sessionResponse?.syncSelection();
                    clearTemporaryHold('The selected session date does not match the selected available date.');
                    showError(dateError, 'This session belongs to ' + sessionDateValue + '. Please select a session for ' + selectedDate + '.');
                    return;
                }
                dateInput.value = selectedDate || (/^\d{4}-\d{2}-\d{2}$/.test(sessionDateValue) ? sessionDateValue : '');
                clearTemporaryHold('Select a live SVP session to load its exact date, then create a temporary hold.');
                clearError(dateError);

                if (!sessionId) {
                    return;
                }

                if (!dateInput.value) {
                    showError(dateError, 'The selected SVP session did not return an exam date. Please select another session.');
                    return;
                }

                temporaryHoldPanel?.classList.remove('hidden');
                temporaryHoldButton.disabled = false;
            });
        }
    })();
</script>
@endsection