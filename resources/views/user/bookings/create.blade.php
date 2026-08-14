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
                <p class="text-2xl font-bold text-slate-900">{{ number_format($wallet?->available_balance ?? 0, 2) }} <span class="text-sm font-medium text-slate-500">SAR</span></p>
                <p class="text-xs text-slate-400 mt-1">Reserved: {{ number_format($wallet?->reserved_balance ?? 0, 2) }} SAR</p>
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
                    <label for="occupation_id" class="block text-sm font-medium text-slate-700 mb-1">Occupation</label>
                    <div class="relative">
                        <input type="text" id="occupation-search" placeholder="Search occupation..." 
                            class="w-full rounded-xl border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500 pl-10 pr-3" autocomplete="off">
                        <select name="occupation_id" id="occupation_id" required
                            class="w-full rounded-xl border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500" style="display:none;">
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
                                <option value="{{ $o['id'] ?? '' }}" {{ old('occupation_id') == ($o['id'] ?? '') ? 'selected' : '' }}>{{ $o['name'] ?? $o['title'] ?? $o['id'] ?? '' }}</option>
                            @endforeach
                        </select>
                        <div class="absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none text-slate-400">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M9.75 9.75c0 1.568 1.273 2.84 2.84 2.84s2.84-1.273 2.84-2.84-1.273-2.84-2.84-2.84S9.75 8.182 9.75 9.75z"/></svg>
                        </div>
                    </div>
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
            </div>
        </div>

        {{-- Session, date, and live SVP payment routing --}}
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 space-y-4">
            <p class="text-xs font-medium text-slate-400 uppercase tracking-wide">Session &amp; SVP payment route</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="exam_session_id" class="block text-sm font-medium text-slate-700 mb-1">Exam Session</label>
                    <select name="exam_session_id" id="exam_session_id" required
                        class="w-full rounded-xl border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
                        <option value="">Select…</option>
                    </select>
                    <input type="hidden" name="exam_session_name" id="exam_session_name" value="">
                    <input type="hidden" name="temporary_hold_id" id="temporary_hold_id" value="">
                    <input type="hidden" name="temporary_hold_expires_at" id="temporary_hold_expires_at" value="">
                    @error('temporary_hold_id')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                    @error('exam_session_id')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="exam_date" class="block text-sm font-medium text-slate-700 mb-1">Exam Date <span class="text-slate-400 font-normal">(from selected SVP session)</span></label>
                    <input type="date" name="exam_date" id="exam_date" value="{{ old('exam_date') }}" required readonly
                        class="w-full rounded-xl border-slate-200 bg-slate-50 text-sm focus:border-brand-500 focus:ring-brand-500">
                    <p id="date-error" class="hidden text-red-600 text-xs mt-1"></p>
                    <p class="text-xs text-slate-400 mt-1">The date is supplied by the exact center-specific SVP session and cannot be changed.</p>
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
                    <label for="language_code" class="block text-sm font-medium text-slate-700 mb-1">SVP Language Code</label>
                    <input type="text" name="language_code" id="language_code" required maxlength="20"
                        value="{{ old('language_code', config('svp.default_language_code', 'LOABB')) }}"
                        placeholder="e.g. LOABB"
                        class="w-full rounded-xl border-slate-200 text-sm uppercase focus:border-brand-500 focus:ring-brand-500">
                    <p class="text-xs text-slate-400 mt-1">Use the SVP Prometric code, not an ISO code such as <code>en</code>.</p>
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
    const citySelect = document.getElementById('city_id');
    const categorySelect = document.getElementById('category_id');
    const testCenterSelect = document.getElementById('test_center_id');
    const testCenterNameInput = document.getElementById('test_center_name');
    const sessionSelect = document.getElementById('exam_session_id');
    const sessionNameInput = document.getElementById('exam_session_name');
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
            if (!response.ok || body.success === false) throw new Error(body.error || 'SVP could not create the temporary hold.');
            const hold = body.data || body;
            const holdId = hold.id ?? hold.hold_id ?? hold.temporary_hold_id;
            if (!holdId) throw new Error('SVP returned no temporary hold ID.');
            temporaryHoldIdInput.value = holdId;
            temporaryHoldExpiresInput.value = hold.expired_at || hold.expires_at || '';
            temporaryHoldStatus.classList.remove('text-red-700');
            confirmBookingButton.disabled = false;
            temporaryHoldStatus.textContent = 'Hold #' + holdId + ' created' + (temporaryHoldExpiresInput.value ? ' — expires ' + formatHoldExpiry(temporaryHoldExpiresInput.value) : '.') + ' You may now confirm the booking.';
        }).catch(error => {
            clearTemporaryHold(error.message);
            if (temporaryHoldStatus) temporaryHoldStatus.classList.add('text-red-700');
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
        (items || []).forEach(function (item) {
            const option = document.createElement('option');
            const value = item[valueKey] ?? '';
            const baseLabel = item[labelKey] || value || '';
            const centerId = item.test_center_id ?? item.site_id ?? item.id;
            const centerName = item.test_center_name ?? item.site_name ?? item.test_center?.name;
            option.value = value;
            option.dataset.name = item.name || baseLabel;
            option.dataset.centerName = centerName || '';
            option.dataset.centerId = centerId || '';
            option.dataset.date = item.exam_date || item.test_date || item.date || item.start_date_in_browser_time_zone || item.start_date_in_tc_time_zone || '';
            if (select === testCenterSelect && centerId) {
                option.textContent = (centerName || baseLabel) + ' — SVP ID: ' + centerId;
            } else if (select === sessionSelect) {
                const sessionCenter = centerName || testCenterNameInput?.value || '';
                const sessionCenterId = item.test_center_id ?? item.site_id ?? testCenterSelect?.value ?? '';
                const suffix = sessionCenterId ? ' — ' + (sessionCenter || 'Test center') + ' (SVP ID: ' + sessionCenterId + ')' : '';
                option.textContent = baseLabel + suffix;
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

    if (occupationSelect) {
        occupationSelect.addEventListener('change', async function () {
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

            if (!occupationId) {
                return;
            }

            void loadCreditStatus();

            try {
                setLoading(categorySelect, true);
                const catData = await fetchJSON("{{ route('user.bookings.lookup.categories') }}?occupation_id=" + encodeURIComponent(occupationId));
                const categories = (catData && Array.isArray(catData.data)) ? catData.data : [];
                populateSelect(categorySelect, categories, 'id', 'name');
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
                const centers = (data && Array.isArray(data.data)) ? data.data : [];
                populateSelect(testCenterSelect, centers, 'id', 'name');
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
            populateSelect(sessionSelect, []);
            dateInput.value = '';
            clearTemporaryHold('Select a session and date, then create a temporary hold before confirming the booking.');

            if (!testCenterId || !city || !categoryId) {
                return;
            }

            try {
                setLoading(sessionSelect, true);
                const params = new URLSearchParams();
                if (city) params.set('city', city);
                params.set('category_id', categoryId);
                params.set('test_center_id', testCenterId);
                const data = await fetchJSON("{{ route('user.bookings.lookup.sessions') }}?" + params.toString());
                const sessions = data?.data?.sessions || data?.data?.exam_sessions || data?.sessions || data?.exam_sessions || [];
                populateSelect(sessionSelect, sessions, 'id', 'name');
            } catch (e) {
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
            if (sessionNameInput) sessionNameInput.value = selectedSessionOption?.dataset?.name || selectedSessionOption?.textContent || '';

            // The selected session is already narrowed to one live SVP center.
            // Its own date is authoritative and must never be overwritten by a
            // broader category/city available_dates response.
            const sessionDate = (selectedSessionOption?.dataset?.date || '').substring(0, 10);
            dateInput.value = /^\d{4}-\d{2}-\d{2}$/.test(sessionDate) ? sessionDate : '';
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
