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
                <p class="mt-1 text-sm text-slate-500">Only sessions returned by the authenticated SVP availability endpoint are shown.</p>
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
                    <thead class="bg-white text-slate-500"><tr><th class="px-5 py-3 text-left font-semibold">Center Name</th><th class="px-5 py-3 text-left font-semibold">Exam Slot</th><th class="px-5 py-3 text-center font-semibold">Sessions</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                    @foreach ($rows as $row)
                        <tr>
                            <td class="px-5 py-3 font-medium text-slate-800">{{ $row['center_name'] }}</td>
                            <td class="px-5 py-3"><span class="font-semibold text-emerald-600">Available</span></td>
                            <td class="px-5 py-3 text-center font-semibold text-slate-700">{{ $row['session_count'] }}</td>
                        </tr>
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
                <div class="overflow-x-auto"><table class="min-w-full text-sm"><thead class="bg-white text-slate-500"><tr><th class="px-5 py-3 text-left font-semibold">Center Name</th><th class="px-5 py-3 text-left font-semibold">Exam Slot</th><th class="px-5 py-3 text-center font-semibold">Sessions</th></tr></thead><tbody class="divide-y divide-slate-100">
                ${items.map(row => `<tr><td class="px-5 py-3 font-medium text-slate-800">${esc(row.center_name)}</td><td class="px-5 py-3"><span class="font-semibold text-emerald-600">Available</span></td><td class="px-5 py-3 text-center font-semibold text-slate-700">${esc(row.session_count)}</td></tr>`).join('')}
                </tbody></table></div>
            </section>`).join('');
    }

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
