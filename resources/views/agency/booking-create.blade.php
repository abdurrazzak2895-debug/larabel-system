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
        <div class="bg-white rounded-xl border border-slate-200 p-5 space-y-4">
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

        {{-- Test Center --}}
        <div class="bg-white rounded-xl border border-slate-200 p-5 space-y-4" id="test-center-section" style="display:none;">
            <p class="text-xs font-medium text-slate-400 uppercase tracking-wide">Test Center</p>
            <div>
                <label for="test_center_id" class="block text-sm font-medium text-slate-700 mb-1">Test Center</label>
                <select name="test_center_id" id="test_center_id"
                    class="w-full rounded-lg border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
                    <option value="">Select…</option>
                </select>
                <input type="hidden" name="test_center_name" id="test_center_name" value="">
                <p id="test-center-error" class="hidden text-red-600 text-xs mt-1"></p>
                <p id="dhaka-center-summary" class="text-xs text-slate-400 mt-1">Select a city to load the live SVP test centers.</p>
            </div>
        </div>

        {{-- Session, date, and live SVP payment routing --}}
        <div class="bg-white rounded-xl border border-slate-200 p-5 space-y-4">
            <p class="text-xs font-medium text-slate-400 uppercase tracking-wide">Available Sessions — date-first PACC booking</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="exam_session_id" class="block text-sm font-medium text-slate-700 mb-1">Available Session Date</label>
                    <select name="exam_session_id" id="exam_session_id" required
                        class="w-full rounded-lg border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
                        <option value="">Select…</option>
                    </select>
                    <input type="hidden" name="exam_session_name" id="exam_session_name" value="">
                    <input type="hidden" name="temporary_hold_id" id="temporary_hold_id" value="">
                    <input type="hidden" name="temporary_hold_expires_at" id="temporary_hold_expires_at" value="">
                    <div id="session-shift-summary" class="hidden mt-3 rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs text-slate-600"></div>
                    <p id="session-center-error" class="hidden mt-2 rounded-lg border border-red-200 bg-red-50 p-2 text-xs text-red-700"></p>
                    @error('temporary_hold_id')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                    <p id="session-error" class="hidden text-red-600 text-xs mt-1"></p>
                    @error('exam_session_id')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="exam_date" class="block text-sm font-medium text-slate-700 mb-1">Exam Date <span class="text-slate-400 font-normal">(from selected SVP session)</span></label>
                    <input type="date" name="exam_date" id="exam_date" required readonly
                        class="w-full rounded-lg border-slate-200 bg-slate-50 text-sm focus:border-brand-500 focus:ring-brand-500">
                    <p id="date-error" class="hidden text-red-600 text-xs mt-1"></p>
                    <p class="text-xs text-slate-400 mt-1">PACC assigns the exact start time and session at reservation. We reserve only at the selected center; if the selected session is unavailable there, the next available date at that same center is used.</p>
                    @error('exam_date')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                </div>
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
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="language_code" class="block text-sm font-medium text-slate-700 mb-1">SVP Language Code</label>
                    <input type="text" name="language_code" id="language_code" required maxlength="20"
                        value="{{ old('language_code', config('svp.default_language_code', 'LOABB')) }}"
                        placeholder="e.g. LOABB"
                        class="w-full rounded-lg border-slate-200 text-sm uppercase focus:border-brand-500 focus:ring-brand-500">
                    <p class="text-xs text-slate-400 mt-1">Use the SVP Prometric code, not an ISO code such as <code>en</code>.</p>
                </div>
                <input type="hidden" name="methodology" value="{{ config('svp.default_methodology', 'in_person') }}">
            </div>
            <div id="svp-credit-panel" class="rounded-lg border border-sky-200 bg-sky-50 p-4">
                <p class="text-sm font-semibold text-sky-900">SVP reservation credit</p>
                <p id="svp-credit-status" class="text-xs text-sky-800 mt-1">Select a candidate and occupation to check the live SVP credit. If no credit is available, confirmation will open the official SVP card-payment page.</p>
                <p class="text-xs text-sky-700 mt-2">The payable amount is set by SVP after reservation creation; it is not entered in this form.</p>
            </div>
        </div>

        {{-- Notes --}}
        <div class="bg-white rounded-xl border border-slate-200 p-5">
            <label for="notes" class="block text-sm font-medium text-slate-700 mb-1">Notes <span class="text-slate-400 font-normal">(optional)</span></label>
            <textarea name="notes" id="notes" rows="3" maxlength="500" placeholder="Anything the SVP booking should know…"
                class="w-full rounded-lg border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500"></textarea>
            @error('notes')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
        </div>

        {{-- Actions --}}
        <div class="flex items-center gap-3">
            <button type="submit" id="confirm-booking-button" disabled class="inline-flex items-center gap-2 px-5 py-2.5 bg-brand-600 hover:bg-brand-700 disabled:opacity-50 disabled:cursor-not-allowed text-white text-sm font-medium rounded-lg transition">
                Confirm &amp; Book
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
        const testCenterSelect = document.getElementById('test_center_id');
        const testCenterNameInput = document.getElementById('test_center_name');
        const testCenterError = document.getElementById('test-center-error');
        const dhakaCenterSummary = document.getElementById('dhaka-center-summary');
        const testCenterSection = document.getElementById('test-center-section');
        const sessionSelect = document.getElementById('exam_session_id');
        const sessionNameInput = document.getElementById('exam_session_name');
        const sessionShiftSummary = document.getElementById('session-shift-summary');
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
            return session?.test_center_name || session?.site_name || session?.center_name || session?.test_center?.name || session?.site?.name || session?.center?.name || 'Unknown center';
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
                    return '<div class="ml-2 ' + (matches ? 'text-slate-600' : 'text-red-700') + '"><span class="font-medium">' + escapeHtml(shiftLabel(session, index)) + '</span> · Session ' + escapeHtml(String(sessionId).slice(0, 18)) + ' · Center ' + escapeHtml(centerId || 'unknown') + ' — ' + escapeHtml(sessionCenterName(session)) + (matches ? '' : ' · <strong>BLOCKED: center mismatch</strong>') + '</div>';
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
                    sessionCenterError.textContent = 'Blocked: session center ID ' + selectedSessionOption.dataset.centerId + ' does not match selected center ID ' + testCenterSelect.value + '.';
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
            temporaryHoldRequest = fetch("{{ route('agency.bookings.temporary-hold') }}", {
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
                const baseLabel = item[labelKey] || value || '';
                const centerId = item.test_center_id ?? item.site_id ?? null;
                const centerName = item.test_center_name ?? item.site_name ?? item.test_center?.name;
                option.value = value;
                option.dataset.name = item.name || baseLabel;
                option.dataset.centerName = centerName || '';
                option.dataset.centerId = centerId || '';
                option.dataset.date = item.exam_date || item.test_date || item.date || item.start_date_in_browser_time_zone || item.start_date_in_tc_time_zone || '';
                if (select === testCenterSelect && centerId) {
                    option.textContent = (centerName || baseLabel) + ' — SVP ID: ' + centerId;
                } else if (select === sessionSelect) {
                    const date = option.dataset.date || 'Unknown date';
                    const sameDateIndex = sessionDateCounts[date] || 0;
                    sessionDateCounts[date] = sameDateIndex + 1;
                    const centerText = centerId ? ' · Center ' + centerId : ' · Center metadata unavailable';
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

        // Seed the cache from the options the server already rendered, so the
        // dropdown works instantly without an extra request when available.
        function seedOccupationsFromServer() {
            if (occupationsCache.length > 0) return;
            if (!occupationSelect) return;
            occupationSelect.querySelectorAll('option').forEach(function (opt) {
                const name = opt.textContent.trim();
                if (opt.value && name && name.toLowerCase() !== 'load' && name.toLowerCase() !== 'loading') {
                    occupationsCache.push({ id: opt.value, name: name });
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
                const url = "{{ route('agency.bookings.lookup.occupations') }}?page=1" + (searchTerm ? '&search=' + encodeURIComponent(searchTerm) : '');
                const data = await fetchJSON(url);
                const occupations = data.data?.occupations || data.data || data.occupations || [];
                occupationsCache = (Array.isArray(occupations) ? occupations : []).map(function (o) {
                    return { id: o.id || o.occupation_id || '', name: o.name || o.english_name || o.arabic_name || o.title || o.id || o.occupation_id || '' };
                });
                occupationsLoaded = true;
                clearError(occupationError);
                return true;
            } catch (e) {
                showError(occupationError, 'Could not load occupations. The SVP service is unreachable — please try again in a moment.');
                console.error(e);
                return false;
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
                populateSelect(testCenterSelect, []);
                populateSelect(sessionSelect, []);
                if (testCenterNameInput) testCenterNameInput.value = '';
                if (sessionNameInput) sessionNameInput.value = '';
                dateInput.value = '';
                clearTemporaryHold('Select a session and date, then create a temporary hold before confirming the booking.');
                clearError(cityError);
                clearError(categoryError);
                clearError(testCenterError);
                clearError(sessionError);
                clearError(dateError);

                if (!occupationId) {
                    return;
                }

                void loadCreditStatus();

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
                populateSelect(testCenterSelect, []);
                populateSelect(sessionSelect, []);
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

        if (citySelect) {
            citySelect.addEventListener('change', async function () {
                const city = citySelect.value;
                const categoryId = categorySelect.value;
                testCenterSection.style.display = 'none';
                populateSelect(testCenterSelect, []);
                populateSelect(sessionSelect, []);
                if (testCenterNameInput) testCenterNameInput.value = '';
                if (sessionNameInput) sessionNameInput.value = '';
                dateInput.value = '';
                clearTemporaryHold('Select a session and date, then create a temporary hold before confirming the booking.');
                clearError(testCenterError);
                clearError(sessionError);
                clearError(dateError);

                if (!city || !categoryId) {
                    return;
                }

                try {
                    setLoading(testCenterSelect, true);
                    const url = "{{ route('agency.bookings.lookup.test-centers') }}?city=" + encodeURIComponent(city) + "&category_id=" + encodeURIComponent(categoryId);
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
                    } else {
                        showError(testCenterError, 'No test centers available for the selected city.');
                    }
                } catch (e) {
                    showError(testCenterError, 'Could not load test centers. The SVP service is unreachable — please try again.');
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
                populateSelect(sessionSelect, []);
                dateInput.value = '';
                clearTemporaryHold('Select a session and date, then create a temporary hold before confirming the booking.');
                clearError(sessionError);
                clearError(dateError);

                if (!testCenterId || !city || !categoryId) {
                    return;
                }

                try {
                    setLoading(sessionSelect, true);
                    const params = new URLSearchParams({
                        city: city,
                        category_id: categoryId,
                        test_center_id: testCenterId
                    });
                    const data = await fetchJSON("{{ route('agency.bookings.lookup.sessions') }}?" + params.toString());
                    const sessions = data?.data?.sessions || data?.data?.exam_sessions || data?.sessions || data?.exam_sessions || [];
                    renderSessionShiftSummary(sessions);
                    populateSelect(sessionSelect, sessions, 'id', 'name');
                    if (sessions.length === 0) {
                        showError(sessionError, 'No exam sessions available for the selected test center.');
                    }
                } catch (e) {
                    showError(sessionError, 'Could not load exam sessions. The SVP service is unreachable — please try again.');
                    console.error(e);
                } finally {
                    setLoading(sessionSelect, false);
                }
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
                        sessionCenterError.textContent = 'Blocked: selected session center ID ' + selectedSessionOption.dataset.centerId + ' does not match selected center ID ' + testCenterSelect.value + '.';
                        sessionCenterError.classList.remove('hidden');
                    }
                    return;
                }
                if (sessionNameInput) sessionNameInput.value = selectedSessionOption?.dataset?.name || selectedSessionOption?.textContent || '';

                // A session lookup is already scoped to the selected center. Its
                // own date is therefore authoritative. Do not overwrite it with
                // the category/city-wide available_dates response.
                const sessionDate = (selectedSessionOption?.dataset?.date || '').substring(0, 10);
                dateInput.value = /^\d{4}-\d{2}-\d{2}$/.test(sessionDate) ? sessionDate : '';
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