@extends('layouts.user')

@section('title', 'Reschedule SVP Reservation')
@section('page-title', 'Reschedule SVP Reservation')

@section('content')
@php
    $context = array_merge([
        'occupation_id' => null,
        'category_id' => null,
        'city' => null,
        'test_center_id' => null,
        'test_center_name' => null,
        'current_exam_date' => null,
        'current_exam_session_id' => null,
        'methodology' => config('svp.default_methodology', 'in_person'),
    ], $context ?? []);
    $canLookup = filled($context['category_id']) && filled($context['city']) && filled($context['test_center_id']);
@endphp

<div class="max-w-4xl space-y-6">
    <div class="flex items-center gap-3">
        <a href="{{ route('user.bookings.index') }}" class="w-9 h-9 rounded-xl border border-slate-200 bg-white text-slate-500 flex items-center justify-center hover:text-slate-900 hover:border-slate-300 transition" aria-label="Back to My Bookings">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        </a>
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Reschedule SVP Reservation</h1>
            <p class="text-sm text-slate-500 mt-1">Choose a new available date and session without leaving this portal.</p>
        </div>
    </div>

    @if (session('error'))
        <div class="px-4 py-3 rounded-xl bg-red-50 border border-red-200 text-red-700 text-sm">{{ session('error') }}</div>
    @endif

    <div class="rounded-2xl border border-indigo-100 bg-indigo-50/60 p-5">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-xs uppercase tracking-wide font-semibold text-indigo-600">Reservation #{{ $reservation }}</p>
                <h2 class="mt-1 text-lg font-semibold text-slate-900">{{ data_get($reservationData, 'full_name') ?? data_get($reservationData, 'candidate.full_name') ?? 'SVP candidate' }}</h2>
                <p class="mt-1 text-sm text-slate-600">{{ data_get($reservationData, 'occupation.name') ?? data_get($reservationData, 'occupation.english_name') ?? 'Occupation unavailable' }}</p>
            </div>
            <div class="text-sm text-right text-slate-600">
                <div>Current date: <span class="font-semibold text-slate-900">{{ $context['current_exam_date'] ?: 'Pending' }}</span></div>
                <div class="mt-1">Locked center: <span class="font-semibold text-slate-900">{{ $context['test_center_name'] ?: 'Center '.$context['test_center_id'] }}</span></div>
                @if ($context['test_center_id'])
                    <div class="font-mono text-xs text-slate-500 mt-1">Center ID {{ $context['test_center_id'] }}</div>
                @endif
            </div>
        </div>
        <p class="mt-4 text-xs leading-5 text-indigo-800">Only sessions returned for this reservation’s original center are selectable. The portal will reject a session whose live SVP center or date does not match.</p>
    </div>

    @if (! $canLookup)
        <div class="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-800">
            SVP did not return enough reservation context to safely load center-scoped sessions. The reservation was not changed. Return to My Bookings, refresh the live reservations, and try again.
        </div>
    @else
        <form method="POST" action="{{ route('user.bookings.svp-reschedule.submit', ['reservation' => $reservation]) }}" class="rounded-2xl border border-slate-200 bg-white shadow-sm p-6 space-y-5" id="reschedule-form">
            @csrf
            <input type="hidden" name="category_id" id="category_id" value="{{ $context['category_id'] }}">
            <input type="hidden" name="city" id="city" value="{{ $context['city'] }}">
            <input type="hidden" name="test_center_id" id="test_center_id" value="{{ $context['test_center_id'] }}">
            <input type="hidden" name="methodology" value="{{ $context['methodology'] }}">

            <div>
                <label for="exam_date" class="block text-sm font-medium text-slate-700 mb-1">New exam date</label>
                <select id="exam_date" name="exam_date" required class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-500/30 focus:border-indigo-400">
                    <option value="">Loading dates…</option>
                </select>
                <p class="mt-1 text-xs text-slate-400">Dates are loaded from the SVP API for the locked center.</p>
            </div>

            <div>
                <label for="exam_session_id" class="block text-sm font-medium text-slate-700 mb-1">Available session / shift</label>
                <select id="exam_session_id" name="exam_session_id" required disabled class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-500/30 focus:border-indigo-400">
                    <option value="">Select a date first</option>
                </select>
                <p id="session-status" class="mt-1 text-xs text-slate-400">Loading center-scoped sessions…</p>
            </div>

            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pt-2">
                <a href="{{ route('user.bookings.index') }}" class="inline-flex items-center justify-center px-4 py-2.5 rounded-xl border border-slate-200 text-sm font-medium text-slate-600 hover:bg-slate-50">Back to My Bookings</a>
                <button type="submit" id="reschedule-submit" disabled class="inline-flex items-center justify-center px-5 py-2.5 rounded-xl bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed">Confirm Reschedule Through SVP</button>
            </div>
        </form>
    @endif
</div>

@if ($canLookup)
<script>
(() => {
    const dateSelect = document.getElementById('exam_date');
    const sessionSelect = document.getElementById('exam_session_id');
    const status = document.getElementById('session-status');
    const submit = document.getElementById('reschedule-submit');
    const categoryId = @json((string) $context['category_id']);
    const city = @json((string) $context['city']);
    const centerId = @json((string) $context['test_center_id']);
    const currentDate = @json((string) ($context['current_exam_date'] ?? ''));

    const sessionDate = (session) => String(session?.exam_date || session?.test_date || session?.date || session?.start_date_in_browser_time_zone || session?.start_date_in_tc_time_zone || '').substring(0, 10);
    const sessionCenter = (session) => String(session?.test_center_id ?? session?.site_id ?? session?.center_id ?? session?.test_center?.id ?? session?.site?.id ?? session?.center?.id ?? '');
    const sessionId = (session) => String(session?.id || session?.exam_session_id || '');
    const sessionName = (session) => String(session?.name || session?.session_name || session?.shift_name || session?.shift || 'SVP session');
    const sessionsFrom = (payload) => payload?.data?.sessions || payload?.data?.exam_sessions || payload?.sessions || payload?.exam_sessions || [];

    let sessions = [];

    const renderSessions = () => {
        const selectedDate = dateSelect.value;
        sessionSelect.innerHTML = '<option value="">Select a session / shift</option>';
        sessionSelect.disabled = true;
        submit.disabled = true;
        const matching = sessions.filter((session) => sessionDate(session) === selectedDate && sessionCenter(session) === centerId);
        matching.forEach((session) => {
            const id = sessionId(session);
            if (!id) return;
            const option = document.createElement('option');
            option.value = id;
            option.textContent = sessionName(session) + ' · ' + id;
            sessionSelect.appendChild(option);
        });
        if (matching.length) {
            sessionSelect.disabled = false;
            status.textContent = matching.length + ' session(s) available at the locked center.';
        } else {
            status.textContent = 'No session is currently available at the locked center for this date.';
        }
    };

    const loadSessions = async () => {
        const params = new URLSearchParams({ city, category_id: categoryId, test_center_id: centerId });
        status.textContent = 'Loading live SVP sessions for the locked center…';
        try {
            const response = await fetch('{{ route('user.bookings.lookup.sessions') }}?' + params.toString(), { headers: { Accept: 'application/json' } });
            const payload = await response.json();
            if (!response.ok) throw new Error(payload.error || 'Unable to load sessions.');
            sessions = sessionsFrom(payload).filter((session) => sessionCenter(session) === centerId && sessionDate(session));
            const dates = [...new Set(sessions.map(sessionDate))].sort();
            dateSelect.innerHTML = '<option value="">Select a new date</option>';
            dates.forEach((date) => {
                const option = document.createElement('option');
                option.value = date;
                option.textContent = date + (date === currentDate ? ' (current date)' : '');
                dateSelect.appendChild(option);
            });
            if (!dates.length) {
                status.textContent = 'SVP returned no available sessions for the locked center.';
                return;
            }
            dateSelect.value = dates.find((date) => date !== currentDate) || dates[0];
            renderSessions();
        } catch (error) {
            dateSelect.innerHTML = '<option value="">Unable to load dates</option>';
            status.textContent = error.message || 'Unable to load sessions from SVP.';
        }
    };

    dateSelect.addEventListener('change', renderSessions);
    sessionSelect.addEventListener('change', () => { submit.disabled = !sessionSelect.value; });
    loadSessions();
})();
</script>
@endif
@endsection
