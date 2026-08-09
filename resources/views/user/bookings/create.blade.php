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
                    <select name="city_id" id="city_id" class="w-full rounded-xl border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
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
            </div>
        </div>

        {{-- Session + date + amount --}}
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 space-y-4">
            <p class="text-xs font-medium text-slate-400 uppercase tracking-wide">Session &amp; Amount</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="exam_session_id" class="block text-sm font-medium text-slate-700 mb-1">Exam Session</label>
                    <select name="exam_session_id" id="exam_session_id" required
                        class="w-full rounded-xl border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
                        <option value="">Select…</option>
                    </select>
                    @error('exam_session_id')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="exam_date" class="block text-sm font-medium text-slate-700 mb-1">Exam Date</label>
                    <input type="date" name="exam_date" id="exam_date" value="{{ old('exam_date') }}" required
                        class="w-full rounded-xl border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
                    @error('exam_date')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                </div>
            </div>
            <div>
                <label for="amount" class="block text-sm font-medium text-slate-700 mb-1">Amount (SAR)</label>
                <input type="number" step="0.01" min="1" name="amount" id="amount" value="{{ old('amount') }}" required placeholder="0.00"
                    class="w-full md:w-64 rounded-xl border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
                @error('amount')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
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
            <button type="submit" class="inline-flex items-center gap-2 px-5 py-2.5 bg-gradient-to-r from-indigo-500 to-fuchsia-500 hover:from-indigo-600 hover:to-fuchsia-600 text-white text-sm font-semibold rounded-xl shadow-lg shadow-indigo-500/25 transition">
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
    const occupationSelect = document.getElementById('occupation_id');
    const citySelect = document.getElementById('city_id');
    const categorySelect = document.getElementById('category_id');
    const testCenterSelect = document.getElementById('test_center_id');
    const sessionSelect = document.getElementById('exam_session_id');
    const dateInput = document.getElementById('exam_date');
    const testCenterSection = document.getElementById('test-center-section');

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
            option.value = item[valueKey] || '';
            option.textContent = item[labelKey] || item[valueKey] || '';
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
            populateSelect(testCenterSelect, []);
            populateSelect(sessionSelect, []);
            dateInput.value = '';

            if (!occupationId) {
                populateSelect(citySelect, []);
                populateSelect(categorySelect, []);
                return;
            }

            try {
                setLoading(citySelect, true);
                setLoading(categorySelect, true);
                const cityData = await fetchJSON("{{ route('user.bookings.lookup.cities') }}?occupation_id=" + encodeURIComponent(occupationId));
                const cities = (cityData && Array.isArray(cityData.data)) ? cityData.data : [];
                populateSelect(citySelect, cities, 'name', 'name');

                const catData = await fetchJSON("{{ route('user.bookings.lookup.categories') }}?occupation_id=" + encodeURIComponent(occupationId));
                const categories = (catData && Array.isArray(catData.data)) ? catData.data : [];
                populateSelect(categorySelect, categories, 'id', 'name');
            } catch (e) {
                console.error(e);
            } finally {
                setLoading(citySelect, false);
                setLoading(categorySelect, false);
            }
        });
    }

    if (citySelect) {
        citySelect.addEventListener('change', async function () {
            const city = citySelect.value;
            const occupationId = occupationSelect.value;
            testCenterSection.style.display = 'none';
            populateSelect(testCenterSelect, []);
            populateSelect(sessionSelect, []);
            dateInput.value = '';

            if (!city) {
                return;
            }

            try {
                setLoading(testCenterSelect, true);
                const url = "{{ route('user.bookings.lookup.test-centers') }}?city=" + encodeURIComponent(city) + (occupationId ? "&occupation_id=" + encodeURIComponent(occupationId) : '');
                const data = await fetchJSON(url);
                const centers = (data && data.data && data.data.test_centers) ? data.data.test_centers : [];
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
            const city = citySelect.value;
            const occupationId = occupationSelect.value;
            populateSelect(sessionSelect, []);
            dateInput.value = '';

            if (!testCenterId) {
                return;
            }

            try {
                setLoading(sessionSelect, true);
                const params = new URLSearchParams();
                if (city) params.set('city', city);
                if (occupationId) params.set('occupation_id', occupationId);
                params.set('test_center_id', testCenterId);
                const data = await fetchJSON("{{ route('user.bookings.lookup.sessions') }}?" + params.toString());
                const sessions = (data && data.data && data.data.exam_sessions) ? data.data.exam_sessions : [];
                populateSelect(sessionSelect, sessions, 'id', 'name');
            } catch (e) {
                console.error(e);
            } finally {
                setLoading(sessionSelect, false);
            }
        });
    }

    if (sessionSelect) {
        sessionSelect.addEventListener('change', async function () {
            const sessionId = sessionSelect.value;
            dateInput.value = '';

            if (!sessionId) {
                return;
            }

            try {
                const data = await fetchJSON("{{ route('user.bookings.available-dates') }}?session_id=" + encodeURIComponent(sessionId));
                const dates = (data && data.data && data.data.dates) ? data.data.dates : [];
                if (dates.length > 0) {
                    dateInput.value = dates[0];
                }
            } catch (e) {
                console.error(e);
            }
        });
    }
})();
</script>
@endsection
