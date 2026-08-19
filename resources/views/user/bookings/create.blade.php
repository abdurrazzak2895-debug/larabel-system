@extends('layouts.user')

@section('title', 'New Booking')
@section('page-title', 'New Booking')

@section('content')
<div class="max-w-4xl">
    <div class="flex items-center gap-3 mb-6">
        <a href="{{ route('user.bookings.index') }}" class="w-9 h-9 rounded-xl border border-slate-200 bg-white text-slate-500 flex items-center justify-center hover:text-slate-900 hover:border-slate-300 transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
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

    <form method="POST" action="{{ route('user.bookings.store') }}" class="space-y-6" id="booking-form">
        @csrf

        {{-- Wallet + candidate row --}}
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
                <p class="text-xs font-medium text-slate-400 uppercase tracking-wide mb-2">Wallet Balance</p>
                <p class="text-2xl font-bold text-slate-900">{{ number_format($wallet?->available_balance ?? 0, 2) }} <span class="text-sm font-medium text-slate-500">BDT</span></p>
                <p class="text-xs text-slate-400 mt-1">Reserved: {{ number_format($wallet?->reserved_balance ?? 0, 2) }} BDT</p>
            </div>
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
                <label for="candidate_id" class="block text-xs font-medium text-slate-400 uppercase tracking-wide mb-2">Candidate</label>
                <select name="candidate_id" id="candidate_id" required
                    class="w-full rounded-xl border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
                    <option value="">Select candidate…</option>
                    @foreach ($candidates as $c)
                        <option value="{{ $c->id }}" {{ old('candidate_id') == $c->id ? 'selected' : '' }}>{{ $c->full_name ?? $c->name ?? ('Credential #' . $c->id) }}</option>
                    @endforeach
                </select>
                @error('candidate_id')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                @if ($candidates->isEmpty())
                    <p class="text-xs text-amber-600 mt-2">No candidate synced yet. Complete SVP login to auto-generate your profile as a candidate.</p>
                @endif
            </div>
        </div>

        {{-- Lookups: Occupation / City / Category --}}
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 space-y-4">
            <p class="text-xs font-medium text-slate-400 uppercase tracking-wide">Exam Lookups</p>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label for="occupation-search" class="block text-sm font-medium text-slate-700 mb-1">Occupation</label>
                    <div class="relative" id="occupation-combobox">
                        <input type="text" id="occupation-search" placeholder="Search occupation..."
                            class="w-full rounded-xl border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500 pl-10 pr-9" autocomplete="off"
                            role="combobox" aria-expanded="false" aria-controls="occupation-dropdown" aria-autocomplete="list">
                        <select name="occupation_id" id="occupation_id" required
                            class="w-full rounded-xl border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500" style="display:none;" tabindex="-1" aria-hidden="true">
                            <option value="">Select…</option>
                            @php
                                $occ = data_get($occupations, 'data.occupations')
                                    ?? data_get($occupations, 'data')
                                    ?? data_get($occupations, 'occupations')
                                    ?? $occupations;
                                if (!is_array($occ) && !($occ instanceof \Traversable)) $occ = [];
                                $occ = collect($occ)->filter(fn($item) => is_array($item) || is_object($item))->values();
                            @endphp
                            @foreach ($occ as $o)
                                @php $o = is_array($o) ? $o : (array) $o; @endphp
                                <option value="{{ $o['id'] ?? $o['occupation_id'] ?? '' }}" {{ old('occupation_id') == ($o['id'] ?? $o['occupation_id'] ?? '') ? 'selected' : '' }}>{{ $o['name'] ?? $o['english_name'] ?? $o['arabic_name'] ?? $o['title'] ?? $o['id'] ?? $o['occupation_id'] ?? '' }}</option>
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
                    <select name="city" id="city_id" class="w-full rounded-xl border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
                        <option value="">Select…</option>
                    </select>
                </div>
                <div>
                    <label for="category_id" class="block text-sm font-medium text-slate-700 mb-1">Category</label>
                    <select name="category_id" id="category_id" class="w-full rounded-xl border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
                        <option value="">Select…</option>
                        @php $cats = data_get($categories, 'data', []); if (!is_array($cats) && !($cats instanceof \Traversable)) $cats = []; @endphp
                        @foreach ($cats as $c)
                            @php $c = is_array($c) ? $c : (array) $c; @endphp
                            <option value="{{ $c['id'] ?? '' }}" {{ old('category_id') == ($c['id'] ?? '') ? 'selected' : '' }}>{{ $c['name'] ?? $c['title'] ?? $c['id'] ?? '' }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>

        {{-- Test Center --}}
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 space-y-4" id="test-center-section" style="display:none;">
            <p class="text-xs font-medium text-slate-400 uppercase tracking-wide">Test Center</p>
            <div>
                <label for="test_center_id" class="block text-sm font-medium text-slate-700 mb-1">Test Center</label>
                <select name="test_center_id" id="test_center_id"
                    class="w-full rounded-xl border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
                    <option value="">Select…</option>
                </select>
                <input type="hidden" name="test_center_name" id="test_center_name" value="">
                <p id="dhaka-center-summary" class="text-xs text-slate-400 mt-1">Select a city to load the live SVP test centers.</p>
            </div>
        </div>

        {{-- Session, date, and live SVP payment routing --}}
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 space-y-4">
            <p class="text-xs font-medium text-slate-400 uppercase tracking-wide">Available Sessions — date-first PACC booking</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="available_session_date" class="block text-sm font-medium text-slate-700 mb-1">Available Exam Date</label>
                    <select id="available_session_date" required
                        class="w-full rounded-xl border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
                        <option value="">Select a test center first…</option>
                    </select>
                    <p class="text-xs text-slate-400 mt-1">Every date returned by SVP for the selected center is shown automatically.</p>
                    <label for="exam_session_id" class="block text-sm font-medium text-slate-700 mb-1 mt-3">Available session / shift</label>
                    <select name="exam_session_id" id="exam_session_id" required
                        class="w-full rounded-xl border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
                        <option value="">Select…</option>
                    </select>
                    <input type="hidden" name="exam_session_name" id="exam_session_name" value="">
                    <input type="hidden" name="temporary_hold_id" id="temporary_hold_id" value="">
                    <input type="hidden" name="temporary_hold_expires_at" id="temporary_hold_expires_at" value="">
                    <div id="session-shift-summary" class="hidden mt-3 rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs text-slate-600"></div>
                    <p id="session-center-error" class="hidden mt-2 rounded-lg border border-red-200 bg-red-50 p-2 text-xs text-red-700"></p>
                    @error('temporary_hold_id')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                    @error('exam_session_id')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="exam_date" class="block text-sm font-medium text-slate-700 mb-1">Exam Date <span class="text-slate-400 font-normal">(from selected SVP session)</span></label>
                    <input type="date" name="exam_date" id="exam_date" value="{{ old('exam_date') }}" required readonly
                        class="w-full rounded-xl border-slate-200 bg-slate-50 text-sm focus:border-brand-500 focus:ring-brand-500">
                    <p id="date-error" class="hidden text-red-600 text-xs mt-1"></p>
                    <p class="text-xs text-slate-400 mt-1">PACC assigns the exact start time and session at reservation. We reserve only at the selected center; if the selected session is unavailable there, the next available date at that same center is used.</p>
                    @error('exam_date')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                </div>
            </div>
            <div id="temporary-hold-panel" class="hidden rounded-xl border border-amber-200 bg-amber-50 p-4">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                    <div>
                        <p class="text-sm font-semibold text-amber-900">Temporary SVP seat hold</p>
                        <p id="temporary-hold-status" class="text-xs text-amber-800 mt-1">Select a session and date, then create a temporary hold before confirming the booking.</p>
                    </div>
                    <button type="button" id="create-temporary-hold" disabled
                        class="inline-flex items-center justify-center px-4 py-2 bg-amber-600 hover:bg-amber-700 disabled:opacity-50 disabled:cursor-not-allowed text-white text-sm font-semibold rounded-xl transition">
                        Create temporary hold
                    </button>
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
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
                <input type="hidden" name="methodology" value="{{ config('svp.default_methodology', 'in_person') }}">
            </div>
            <div id="svp-credit-panel" class="rounded-xl border border-sky-200 bg-sky-50 p-4">
                <p class="text-sm font-semibold text-sky-900">SVP reservation credit</p>
                <p id="svp-credit-status" class="text-xs text-sky-800 mt-1">Select a candidate and occupation to check the live SVP credit. If no credit is available, confirmation will open the official SVP card-payment page.</p>
                <p class="text-xs text-sky-700 mt-2">The payable amount is set by SVP after reservation creation; it is not entered in this form.</p>
            </div>
        </div>

        {{-- Notes --}}
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
            <label for="notes" class="block text-sm font-medium text-slate-700 mb-1">Notes <span class="text-slate-400 font-normal">(optional)</span></label>
            <textarea name="notes" id="notes" rows="3" maxlength="500" placeholder="Anything the SVP booking should know…"
                class="w-full rounded-xl border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">{{ old('notes') }}</textarea>
            @error('notes')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
        </div>

        {{-- Actions --}}
        <div class="flex items-center gap-3">
            <button type="submit" id="confirm-booking-button" disabled class="inline-flex items-center gap-2 px-5 py-2.5 bg-gradient-to-r from-indigo-500 to-fuchsia-500 hover:from-indigo-600 hover:to-fuchsia-600 disabled:opacity-50 disabled:cursor-not-allowed text-white text-sm font-semibold rounded-xl shadow-lg shadow-indigo-500/25 transition">
                Confirm &amp; Book
            </button>
            <a href="{{ route('user.bookings.index') }}" class="inline-flex items-center gap-2 px-5 py-2.5 bg-white border border-slate-200 text-slate-700 text-sm font-medium rounded-xl hover:bg-slate-50 transition">
                Cancel
            </a>
        </div>
    </form>
</div>

<script>
(function () {
    const candidateSelect = document.getElementById('candidate_id');
    const occupationSelect = document.getElementById('occupation_id');
    const occupationSearchInput = document.getElementById('occupation-search');
    const occupationDropdown = document.getElementById('occupation-dropdown');
    const occupationDropdownStatus = document.getElementById('occupation-dropdown-status');
    const occupationDropdownList = document.getElementById('occupation-dropdown-list');
    const occupationClear = document.getElementById('occupation-clear');
    const occupationError = document.getElementById('occupation-error');
    const occupationHint = document.getElementById('occupation-hint');
    const citySelect = document.getElementById('city_id');
    const categorySelect = document.getElementById('category_id');
    const testCenterSelect = document.getElementById('test_center_id');
    const testCenterNameInput = document.getElementById('test_center_name');
    const dhakaCenterSummary = document.getElementById('dhaka-center-summary');
    const availableDateSelect = document.getElementById('available_session_date');
    const sessionSelect = document.getElementById('exam_session_id');
    const sessionNameInput = document.getElementById('exam_session_name');
    const sessionShiftSummary = document.getElementById('session-shift-summary');
    const sessionCenterError = document.getElementById('session-center-error');
    const dateInput = document.getElementById('exam_date');
    const dateError = document.getElementById('date-error');
    const testCenterSection = document.getElementById('test-center-section');
    const temporaryHoldPanel = document.getElementById('temporary-hold-panel');
    const temporaryHoldButton = document.getElementById('create-temporary-hold');
    const temporaryHoldStatus = document.getElementById('temporary-hold-status');
    const temporaryHoldIdInput = document.getElementById('temporary_hold_id');
    const temporaryHoldExpiresInput = document.getElementById('temporary_hold_expires_at');
    const confirmBookingButton = document.getElementById('confirm-booking-button');
    const bookingForm = document.getElementById('booking-form');
    const svpCreditStatus = document.getElementById('svp-credit-status');
    let temporaryHoldRequest = null;
    let creditStatusRequest = null;
    let sessionCatalog = [];
    let availableDateCatalog = [];

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
        creditStatusRequest = fetchJSON("{{ route('user.bookings.credit-status') }}?" + params.toString())
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
        return session?.test_center_name || session?.site_name || session?.center_name || session?.test_center?.name || session?.site?.name || session?.center?.name || 'Unknown center';
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
        const filtered = (sessionCatalog || []).filter(session => !date || sessionDate(session) === date);
        renderSessionShiftSummary(sessionCatalog);
        populateSelect(sessionSelect, filtered, 'id', 'name');
        if (sessionSelect) sessionSelect.disabled = filtered.length === 0;
        return filtered;
    }

    async function loadSessionsForDate(date) {
        if (!date || !citySelect.value || !categorySelect.value || !testCenterSelect.value) {
            renderSessionsForDate(date);
            return;
        }
        try {
            setLoading(sessionSelect, true);
            const params = new URLSearchParams({city: citySelect.value, category_id: categorySelect.value, test_center_id: testCenterSelect.value, exam_date: date});
            const data = await fetchJSON("{{ route('user.bookings.lookup.sessions') }}?" + params.toString());
            const sessions = data?.data?.sessions || data?.data?.exam_sessions || data?.sessions || data?.exam_sessions || [];
            mergeSessionCatalog(sessions);
            renderAvailableDates(sessionCatalog, availableDateCatalog);
            availableDateSelect.value = date;
            dateInput.value = date;
            renderSessionsForDate(date);
            if (!sessions.length) {
                sessionSelect.innerHTML = '<option value="">No sessions returned for this date</option>';
                temporaryHoldStatus.textContent = 'SVP returned no available session for this date at the selected center.';
            }
        } catch (error) {
            sessionSelect.innerHTML = '<option value="">Could not load sessions for this date</option>';
            console.error(error);
        } finally {
            setLoading(sessionSelect, false);
        }
    }

    function shiftLabel(session, sameDateIndex) {
        const source = String(session?.shift || session?.session_name || session?.name || '').toLowerCase();
        const match = source.match(/(?:shift|session)\s*([1-9][0-9]*)/);
        const number = match ? Number(match[1]) : sameDateIndex + 1;
        return number === 1 ? 'First Shift' : number === 2 ? 'Second Shift' : number === 3 ? 'Third Shift' : number === 4 ? 'Fourth Shift' : 'Shift ' + number;
    }

    function renderSessionShiftSummary(sessions) {
        if (!sessionShiftSummary) return;
        const selectedCenterId = String(testCenterSelect?.value || '');
        const grouped = {};
        (sessions || []).forEach(function (session) {
            const date = sessionDate(session) || 'Unknown date';
            if (!grouped[date]) grouped[date] = [];
            grouped[date].push(session);
        });
        const html = Object.keys(grouped).sort().map(function (date) {
            const rows = grouped[date].map(function (session, index) {
                const centerId = sessionCenterId(session);
                const matches = centerId !== '' && centerId === selectedCenterId;
                const sessionId = session.id || session.exam_session_id || '';
                return '<div class="ml-2 ' + (matches ? 'text-slate-600' : 'text-red-700') + '"><span class="font-medium">' + escapeHtml(shiftLabel(session, index)) + '</span> · Session ' + escapeHtml(String(sessionId).slice(0, 18)) + ' · ' + escapeHtml(sessionCenterName(session)) + (matches ? '' : ' · <strong>BLOCKED: center mismatch</strong>') + '</div>';
            }).join('');
            return '<div class="mb-2 last:mb-0"><div class="font-semibold text-slate-700">' + escapeHtml(date) + '</div>' + rows + '</div>';
        }).join('');
        sessionShiftSummary.innerHTML = '<div class="mb-1 font-medium text-slate-700">Sessions grouped by date and shift</div>' + (html || '<div>No sessions returned.</div>');
        sessionShiftSummary.classList.remove('hidden');
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
        const selectedSessionOption = sessionSelect.options[sessionSelect.selectedIndex];
        if (selectedSessionOption?.dataset?.centerId && selectedSessionOption.dataset.centerId !== String(testCenterSelect.value)) {
            clearTemporaryHold('The selected session belongs to another test center and is blocked.');
            if (sessionCenterError) {
                const selectedSessionCenterName = selectedSessionOption.dataset.centerName || selectedSessionOption.dataset.name || 'another test center';
                const selectedCenterName = testCenterSelect.options[testCenterSelect.selectedIndex]?.dataset?.centerName || testCenterSelect.options[testCenterSelect.selectedIndex]?.textContent || 'the selected test center';
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
        temporaryHoldStatus.textContent = 'Creating the live SVP temporary hold…';
        temporaryHoldRequest = fetch("{{ route('user.bookings.temporary-hold') }}", {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
            },
            body: JSON.stringify(payload)
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

    function showDateError(message) {
        if (!dateError) return;
        dateError.textContent = message;
        dateError.classList.remove('hidden');
    }

    function clearDateError() {
        if (!dateError) return;
        dateError.textContent = '';
        dateError.classList.add('hidden');
    }

    function setLoading(select, isLoading) {
        if (!select) return;
        select.disabled = isLoading;
        if (isLoading) {
            select.insertAdjacentHTML('afterend', '<span class="text-xs text-slate-400 ml-2">Loading…</span>');
        } else {
            const loading = select.parentElement.querySelector('.text-slate-400');
            if (loading && loading.textContent === 'Loading…') {
                loading.remove();
            }
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
            const baseLabel = item[labelKey] || item.name || item.english_name || item.arabic_name || item.title || value || '';
            const centerId = item.test_center_id ?? item.site_id ?? null;
            const centerName = item.test_center_name ?? item.site_name ?? item.center_name ?? item.test_center?.name ?? item.site?.name ?? item.center?.name;
            option.value = value;
            option.dataset.name = item.name || baseLabel;
            option.dataset.centerName = centerName || '';
            option.dataset.centerId = centerId || '';
            option.dataset.date = item.exam_date || item.test_date || item.date || item.start_date_in_browser_time_zone || item.start_date_in_tc_time_zone || '';
            if (select === testCenterSelect && centerId) {
                option.textContent = centerName || baseLabel;
            } else if (select === sessionSelect) {
                const date = option.dataset.date || 'Unknown date';
                const sameDateIndex = sessionDateCounts[date] || 0;
                sessionDateCounts[date] = sameDateIndex + 1;
                const centerText = centerName ? ' · ' + centerName : ' · Center metadata unavailable';
                option.textContent = date + ' — ' + shiftLabel(item, sameDateIndex) + centerText;
                option.disabled = !centerId || (testCenterSelect?.value && String(centerId) !== String(testCenterSelect.value));
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
        if (!response.ok) {
            const text = await response.text();
            throw new Error('HTTP ' + response.status + ': ' + text);
        }
        return response.json();
    }

    let occupationsCache = [];
    let occupationsLoaded = false;
    let occupationsLoading = false;

    function showOccupationError(message) {
        if (!occupationError) return;
        occupationError.textContent = message;
        occupationError.classList.toggle('hidden', !message);
    }

    function seedOccupationsFromServer() {
        if (occupationsCache.length > 0 || !occupationSelect) return;
        occupationSelect.querySelectorAll('option').forEach(function (option) {
            const name = option.textContent.trim();
            if (option.value && name && name.toLowerCase() !== 'load' && name.toLowerCase() !== 'loading') {
                occupationsCache.push({ id: option.value, name: name });
            }
        });
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
        occupationsLoading = true;
        try {
            const url = "{{ route('user.bookings.lookup.occupations') }}?page=1" + (searchTerm ? '&search=' + encodeURIComponent(searchTerm) : '');
            const data = await fetchJSON(url);
            const occupations = data.data?.occupations || data.data || data.occupations || [];
            occupationsCache = (Array.isArray(occupations) ? occupations : []).map(function (occupation) {
                return {
                    id: occupation.id || occupation.occupation_id || '',
                    name: occupation.name || occupation.english_name || occupation.arabic_name || occupation.title || occupation.id || occupation.occupation_id || ''
                };
            }).filter(function (occupation) { return occupation.id && occupation.name; });
            occupationsLoaded = true;
            showOccupationError('');
        } catch (error) {
            showOccupationError('Could not load occupations. The SVP service may be unavailable.');
            console.error(error);
        } finally {
            occupationsLoading = false;
        }
    }

    function renderOccupationDropdown(filter) {
        if (!occupationDropdown || !occupationDropdownStatus || !occupationDropdownList) return;
        const term = String(filter || '').trim().toLowerCase();
        const matches = occupationsCache.filter(function (occupation) {
            return !term || occupation.name.toLowerCase().includes(term);
        });
        occupationDropdownStatus.textContent = matches.length
            ? matches.length + (matches.length === 1 ? ' occupation' : ' occupations')
            : 'No occupations match "' + (filter || '') + '".';
        occupationDropdownList.innerHTML = '';
        matches.slice(0, 100).forEach(function (occupation) {
            const item = document.createElement('li');
            item.className = 'px-3 py-2 text-sm text-slate-700 cursor-pointer hover:bg-brand-50';
            item.textContent = occupation.name;
            item.addEventListener('click', function () {
                occupationSelect.value = occupation.id;
                occupationSearchInput.value = occupation.name;
                occupationClear?.classList.remove('hidden');
                occupationDropdown.classList.add('hidden');
                occupationSearchInput.setAttribute('aria-expanded', 'false');
                occupationSelect.dispatchEvent(new Event('change'));
            });
            occupationDropdownList.appendChild(item);
        });
        occupationDropdown.classList.remove('hidden');
        occupationSearchInput.setAttribute('aria-expanded', 'true');
    }

    async function ensureOccupationsLoaded(searchTerm) {
        const term = String(searchTerm || '').trim();
        if (term || !occupationsLoaded) await loadOccupationsFromApi(term);
        if (occupationsCache.length === 0) seedOccupationsFromServer();
    }

    occupationSearchInput?.addEventListener('focus', function () {
        ensureOccupationsLoaded(occupationSearchInput.value).then(function () { renderOccupationDropdown(occupationSearchInput.value); });
    });
    occupationSearchInput?.addEventListener('input', function () {
        clearTimeout(occupationSearchInput.searchTimer);
        occupationSearchInput.searchTimer = setTimeout(function () {
            ensureOccupationsLoaded(occupationSearchInput.value).then(function () { renderOccupationDropdown(occupationSearchInput.value); });
        }, 300);
    });
    occupationSearchInput?.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            ensureOccupationsLoaded(occupationSearchInput.value).then(function () { renderOccupationDropdown(occupationSearchInput.value); });
        } else if (event.key === 'Escape') {
            occupationDropdown?.classList.add('hidden');
            occupationSearchInput.setAttribute('aria-expanded', 'false');
        }
    });
    occupationClear?.addEventListener('click', function () {
        occupationSelect.value = '';
        occupationSearchInput.value = '';
        occupationClear.classList.add('hidden');
        occupationDropdown?.classList.add('hidden');
        occupationSelect.dispatchEvent(new Event('change'));
    });
    document.addEventListener('click', function (event) {
        if (!event.target.closest('#occupation-combobox') && !event.target.closest('#occupation-dropdown')) {
            occupationDropdown?.classList.add('hidden');
            occupationSearchInput?.setAttribute('aria-expanded', 'false');
        }
    });

    if (occupationSelect) {
        syncOccupationSearchDisplay();
        occupationSelect.addEventListener('change', async function () {
            syncOccupationSearchDisplay();
            const occupationId = occupationSelect.value;
            testCenterSection.style.display = 'none';
            populateSelect(citySelect, []);
            populateSelect(categorySelect, []);
            populateSelect(testCenterSelect, []);
            sessionCatalog = [];
            availableDateCatalog = [];
            renderAvailableDates([], []);
            populateSelect(sessionSelect, []);
            if (testCenterNameInput) testCenterNameInput.value = '';
            if (sessionNameInput) sessionNameInput.value = '';
            dateInput.value = '';
            clearTemporaryHold('Select a session and date, then create a temporary hold before confirming the booking.');

            if (!occupationId) {
                return;
            }

            void loadCreditStatus();

            try {
                setLoading(categorySelect, true);
                const catData = await fetchJSON("{{ route('user.bookings.lookup.categories') }}?occupation_id=" + encodeURIComponent(occupationId));
                const categories = (catData && Array.isArray(catData.data)) ? catData.data : [];
                populateSelect(categorySelect, categories, 'id', 'name');
                if (categories.length === 1) {
                    categorySelect.value = String(categories[0].id ?? categories[0].category_id ?? '');
                    categorySelect.dispatchEvent(new Event('change'));
                }
            } catch (e) {
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
            populateSelect(testCenterSelect, []);
            sessionCatalog = [];
            availableDateCatalog = [];
            renderAvailableDates([], []);
            populateSelect(sessionSelect, []);
            if (testCenterNameInput) testCenterNameInput.value = '';
            if (sessionNameInput) sessionNameInput.value = '';
            dateInput.value = '';
            clearTemporaryHold('Select a session and date, then create a temporary hold before confirming the booking.');

            if (!categoryId) {
                return;
            }

            try {
                setLoading(citySelect, true);
                const cityData = await fetchJSON("{{ route('user.bookings.lookup.cities') }}?category_id=" + encodeURIComponent(categoryId));
                const cities = (cityData && Array.isArray(cityData.data)) ? cityData.data : [];
                populateSelect(citySelect, cities, 'name', 'name');
            } catch (e) {
                console.error(e);
            } finally {
                setLoading(citySelect, false);
            }
        });
    }

    if (citySelect) {
        citySelect.addEventListener('change', async function () {
            const city = citySelect.value;
            const categoryId = categorySelect.value;
            testCenterSection.style.display = 'none';
            populateSelect(testCenterSelect, []);
            sessionCatalog = [];
            availableDateCatalog = [];
            renderAvailableDates([], []);
            populateSelect(sessionSelect, []);
            if (testCenterNameInput) testCenterNameInput.value = '';
            if (sessionNameInput) sessionNameInput.value = '';
            dateInput.value = '';
            clearTemporaryHold('Select a session and date, then create a temporary hold before confirming the booking.');

            if (!city || !categoryId) {
                return;
            }

            try {
                setLoading(testCenterSelect, true);
                const url = "{{ route('user.bookings.lookup.test-centers') }}?city=" + encodeURIComponent(city) + "&category_id=" + encodeURIComponent(categoryId);
                const data = await fetchJSON(url);
                const centers = data?.data?.test_centers || (data && Array.isArray(data.data) ? data.data : []);
                populateSelect(testCenterSelect, centers, 'id', 'name');
                if (dhakaCenterSummary) {
                    dhakaCenterSummary.textContent = city.toLowerCase() === 'dhaka'
                        ? 'SVP returned ' + centers.length + ' Dhaka test centers. Booking is locked to the selected center.'
                        : 'SVP returned ' + centers.length + ' live test centers for ' + city + '.';
                }
                if (centers.length > 0) {
                    testCenterSection.style.display = '';
                }
            } catch (e) {
                console.error(e);
            } finally {
                setLoading(testCenterSelect, false);
            }
        });
    }

    if (testCenterSelect) {
        testCenterSelect.addEventListener('change', async function () {
            const testCenterId = testCenterSelect.value;
            const selectedCenterOption = testCenterSelect.options[testCenterSelect.selectedIndex];
            const selectedCenterName = selectedCenterOption?.dataset?.centerName || selectedCenterOption?.textContent?.replace(/\s+—\s+SVP ID:.*$/, '') || '';
            const city = citySelect.value;
            const categoryId = categorySelect.value;
            if (testCenterNameInput) testCenterNameInput.value = selectedCenterName.trim();
            if (sessionNameInput) sessionNameInput.value = '';
            sessionCatalog = [];
            availableDateCatalog = [];
            renderAvailableDates([], []);
            populateSelect(sessionSelect, []);
            dateInput.value = '';
            clearTemporaryHold('Select a session and date, then create a temporary hold before confirming the booking.');

            if (!testCenterId || !city || !categoryId) {
                return;
            }

            try {
                setLoading(sessionSelect, true);
                const params = new URLSearchParams({city: city, category_id: categoryId, test_center_id: testCenterId});
                const data = await fetchJSON("{{ route('user.bookings.lookup.sessions') }}?" + params.toString());
                const sessions = data?.data?.sessions || data?.data?.exam_sessions || data?.sessions || data?.exam_sessions || [];
                availableDateCatalog = data?.data?.available_dates || data?.available_dates || [];
                mergeSessionCatalog(sessions);
                const dates = renderAvailableDates(sessionCatalog, availableDateCatalog);
                renderSessionsForDate('');
                if (dates.length) {
                    availableDateSelect.value = dates[0];
                    dateInput.value = dates[0];
                    await loadSessionsForDate(dates[0]);
                }
            } catch (e) {
                console.error(e);
            } finally {
                setLoading(sessionSelect, false);
            }
        });
    }

    if (availableDateSelect) {
        availableDateSelect.addEventListener('change', async function () {
            const date = availableDateSelect.value;
            dateInput.value = date || '';
            clearTemporaryHold('Select a session for this date, then create a temporary hold before confirming the booking.');
            clearDateError();
            if (!date) {
                populateSelect(sessionSelect, []);
                return;
            }
            await loadSessionsForDate(date);
        });
    }

    if (sessionSelect) {
        sessionSelect.addEventListener('change', function () {
            const sessionId = sessionSelect.value;
            const selectedSessionOption = sessionSelect.options[sessionSelect.selectedIndex];
            clearSessionCenterError();
            if (selectedSessionOption?.dataset?.centerId && selectedSessionOption.dataset.centerId !== String(testCenterSelect.value)) {
                sessionSelect.value = '';
                clearTemporaryHold('The selected session belongs to another center and has been blocked.');
                if (sessionCenterError) {
                    const selectedSessionCenterName = selectedSessionOption.dataset.centerName || selectedSessionOption.dataset.name || 'another test center';
                    const selectedCenterName = testCenterSelect.options[testCenterSelect.selectedIndex]?.dataset?.centerName || testCenterSelect.options[testCenterSelect.selectedIndex]?.textContent || 'the selected test center';
                    sessionCenterError.textContent = 'Blocked: selected session center "' + selectedSessionCenterName + '" does not match selected center "' + selectedCenterName + '".';
                    sessionCenterError.classList.remove('hidden');
                }
                return;
            }
            if (sessionNameInput) sessionNameInput.value = selectedSessionOption?.dataset?.name || selectedSessionOption?.textContent || '';

            const selectedDate = availableDateSelect?.value || '';
            const sessionDateValue = (selectedSessionOption?.dataset?.date || '').substring(0, 10);
            if (selectedDate && sessionDateValue && selectedDate !== sessionDateValue) {
                sessionSelect.value = '';
                clearTemporaryHold('The selected session date does not match the selected available date.');
                showDateError('This session belongs to ' + sessionDateValue + '. Please select a session for ' + selectedDate + '.');
                return;
            }
            dateInput.value = selectedDate || (/^\d{4}-\d{2}-\d{2}$/.test(sessionDateValue) ? sessionDateValue : '');
            clearTemporaryHold('Select a live SVP session to load its exact date, then create a temporary hold.');
            clearDateError();

            if (!sessionId) return;

            if (!dateInput.value) {
                showDateError('The selected SVP session did not return an exam date. Please select another session.');
                return;
            }

            temporaryHoldPanel?.classList.remove('hidden');
            temporaryHoldButton.disabled = false;
        });
    }
})();
</script>
@endsection
