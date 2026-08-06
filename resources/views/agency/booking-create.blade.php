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
                <p class="text-2xl font-bold text-slate-900">{{ number_format($wallet?->available_balance ?? 0, 2) }} <span class="text-sm font-medium text-slate-500">SAR</span></p>
                <p class="text-xs text-slate-400 mt-1">Reserved: {{ number_format($wallet?->reserved_balance ?? 0, 2) }} SAR</p>
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
                            @php $occ = data_get($occupations, 'data.occupations', $occupations); if (!is_array($occ)) $occ = []; @endphp
                            @foreach($occ as $o)
                                @php $o = (array) $o; @endphp
                                <option value="{{ $o['id'] ?? '' }}">{{ $o['name'] ?? $o['title'] ?? $o['id'] }}</option>
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
                    <select name="city_id" id="city_id"
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
                        @php $cats = data_get($categories, 'data.categories', $categories); if (!is_array($cats)) $cats = []; @endphp
                        @foreach($cats as $c)
                            @php $c = (array) $c; @endphp
                            <option value="{{ $c['id'] ?? '' }}">{{ $c['name'] ?? $c['title'] ?? $c['id'] }}</option>
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
                <p id="test-center-error" class="hidden text-red-600 text-xs mt-1"></p>
            </div>
        </div>

        {{-- Session + date + amount --}}
        <div class="bg-white rounded-xl border border-slate-200 p-5 space-y-4">
            <p class="text-xs font-medium text-slate-400 uppercase tracking-wide">Session &amp; Amount</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="exam_session_id" class="block text-sm font-medium text-slate-700 mb-1">Exam Session</label>
                    <select name="exam_session_id" id="exam_session_id" required
                        class="w-full rounded-lg border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
                        <option value="">Select…</option>
                    </select>
                    <p id="session-error" class="hidden text-red-600 text-xs mt-1"></p>
                    @error('exam_session_id')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="exam_date" class="block text-sm font-medium text-slate-700 mb-1">Exam Date</label>
                    <input type="date" name="exam_date" id="exam_date" required
                        class="w-full rounded-lg border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
                    <p id="date-error" class="hidden text-red-600 text-xs mt-1"></p>
                    @error('exam_date')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                </div>
            </div>
            <div>
                <label for="amount" class="block text-sm font-medium text-slate-700 mb-1">Amount (SAR)</label>
                <input type="number" step="0.01" min="1" name="amount" id="amount" required placeholder="0.00"
                    class="w-full md:w-64 rounded-lg border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
                @error('amount')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
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
            <button type="submit" class="inline-flex items-center gap-2 px-5 py-2.5 bg-brand-600 hover:bg-brand-700 text-white text-sm font-medium rounded-lg transition">
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
        const testCenterError = document.getElementById('test-center-error');
        const testCenterSection = document.getElementById('test-center-section');
        const sessionSelect = document.getElementById('exam_session_id');
        const sessionError = document.getElementById('session-error');
        const dateInput = document.getElementById('exam_date');
        const dateError = document.getElementById('date-error');

        // Occupation combobox state.
        let occupationsCache = [];
        let occupationsLoaded = false;
        let occupationsLoading = false;

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

        // Seed the cache from the options the server already rendered, so the
        // dropdown works instantly without an extra request when available.
        function seedOccupationsFromServer() {
            if (occupationsCache.length > 0) return;
            if (!occupationSelect) return;
            occupationSelect.querySelectorAll('option').forEach(function (opt) {
                if (opt.value) {
                    occupationsCache.push({ id: opt.value, name: opt.textContent.trim() });
                }
            });
            occupationsLoaded = occupationsCache.length > 0;
        }

        async function loadOccupationsFromApi(searchTerm) {
            if (occupationsLoading) return;
            occupationsLoading = true;
            try {
                const url = "{{ route('agency.bookings.lookup.occupations') }}?page=1" + (searchTerm ? '&search=' + encodeURIComponent(searchTerm) : '');
                const data = await fetchJSON(url);
                const occupations = (data && data.data && data.data.occupations) ? data.data.occupations : [];
                occupationsCache = occupations.map(function (o) {
                    return { id: o.id || '', name: o.name || o.title || o.id || '' };
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

        async function ensureOccupationsLoaded() {
            seedOccupationsFromServer();
            if (!occupationsLoaded) {
                await loadOccupationsFromApi(occupationSearchInput ? occupationSearchInput.value.trim() : '');
            }
        }

        if (occupationSearchInput) {
            let debounceTimer;

            occupationSearchInput.addEventListener('focus', function () {
                ensureOccupationsLoaded().then(function () {
                    renderOccupationDropdown(occupationSearchInput.value);
                });
            });

            occupationSearchInput.addEventListener('input', function () {
                clearTimeout(debounceTimer);
                clearError(occupationError);
                debounceTimer = setTimeout(function () {
                    ensureOccupationsLoaded().then(function () {
                        renderOccupationDropdown(occupationSearchInput.value);
                    });
                }, 300);
            });

            occupationSearchInput.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    ensureOccupationsLoaded().then(function () {
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
            occupationSelect.addEventListener('change', async function () {
                const occupationId = occupationSelect.value;
                testCenterSection.style.display = 'none';
                populateSelect(testCenterSelect, []);
                populateSelect(sessionSelect, []);
                dateInput.value = '';
                clearError(cityError);
                clearError(categoryError);
                clearError(testCenterError);
                clearError(sessionError);
                clearError(dateError);

                if (!occupationId) {
                    populateSelect(citySelect, []);
                    populateSelect(categorySelect, []);
                    return;
                }

                setLoading(citySelect, true);
                setLoading(categorySelect, true);

                const cityPromise = fetchJSON("{{ route('agency.bookings.lookup.cities') }}?occupation_id=" + encodeURIComponent(occupationId))
                    .then(function (cityData) {
                        const cities = (cityData && cityData.data && cityData.data.cities) ? cityData.data.cities : [];
                        populateSelect(citySelect, cities, 'name', 'name');
                        if (cities.length === 0) {
                            showError(cityError, 'No cities available for this occupation.');
                        }
                    })
                    .catch(function (e) {
                        showError(cityError, 'Could not load cities. The SVP service is unreachable — please try again.');
                        console.error(e);
                    })
                    .finally(function () {
                        setLoading(citySelect, false);
                    });

                const categoryPromise = fetchJSON("{{ route('agency.bookings.lookup.categories') }}?occupation_id=" + encodeURIComponent(occupationId))
                    .then(function (catData) {
                        const categories = (catData && catData.data && catData.data.categories) ? catData.data.categories : [];
                        populateSelect(categorySelect, categories, 'id', 'name');
                        if (categories.length === 0) {
                            showError(categoryError, 'No categories available for this occupation.');
                        }
                    })
                    .catch(function (e) {
                        showError(categoryError, 'Could not load categories. The SVP service is unreachable — please try again.');
                        console.error(e);
                    })
                    .finally(function () {
                        setLoading(categorySelect, false);
                    });

                await Promise.all([cityPromise, categoryPromise]);
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
                clearError(testCenterError);
                clearError(sessionError);
                clearError(dateError);

                if (!city) {
                    return;
                }

                try {
                    setLoading(testCenterSelect, true);
                    const url = "{{ route('agency.bookings.lookup.test-centers') }}?city=" + encodeURIComponent(city) + (occupationId ? "&occupation_id=" + encodeURIComponent(occupationId) : '');
                    const data = await fetchJSON(url);
                    const centers = (data && data.data && data.data.test_centers) ? data.data.test_centers : [];
                    populateSelect(testCenterSelect, centers, 'id', 'name');
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
                const city = citySelect.value;
                const occupationId = occupationSelect.value;
                populateSelect(sessionSelect, []);
                dateInput.value = '';
                clearError(sessionError);
                clearError(dateError);

                if (!testCenterId) {
                    return;
                }

                try {
                    setLoading(sessionSelect, true);
                    const params = new URLSearchParams();
                    if (city) params.set('city', city);
                    if (occupationId) params.set('occupation_id', occupationId);
                    params.set('test_center_id', testCenterId);
                    const data = await fetchJSON("{{ route('agency.bookings.lookup.sessions') }}?" + params.toString());
                    const sessions = (data && data.data && data.data.exam_sessions) ? data.data.exam_sessions : [];
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
            sessionSelect.addEventListener('change', async function () {
                const sessionId = sessionSelect.value;
                dateInput.value = '';
                clearError(dateError);

                if (!sessionId) {
                    return;
                }

                try {
                    const data = await fetchJSON("{{ route('agency.bookings.available-dates') }}?session_id=" + encodeURIComponent(sessionId));
                    const dates = (data && data.data && data.data.dates) ? data.data.dates : [];
                    if (dates.length > 0) {
                        dateInput.value = dates[0];
                    } else {
                        showError(dateError, 'No available dates for this session.');
                    }
                } catch (e) {
                    showError(dateError, 'Could not load available dates. The SVP service is unreachable — please try again.');
                    console.error(e);
                }
            });
        }
    })();
</script>
@endsection