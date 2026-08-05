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
                    <p class="text-xs text-amber-600 mt-2">No candidates on file. Add them under Profile → Credentials first.</p>
                @endif
            </div>
        </div>

        {{-- Lookups: Occupation / City / Category --}}
        <div class="bg-white rounded-xl border border-slate-200 p-5 space-y-4">
            <p class="text-xs font-medium text-slate-400 uppercase tracking-wide">Exam Lookups</p>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label for="occupation_id" class="block text-sm font-medium text-slate-700 mb-1">Occupation</label>
                    <select name="occupation_id" id="occupation_id" required
                        class="w-full rounded-lg border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
                        <option value="">Select…</option>
                        @php $occ = data_get($occupations, 'data.occupations', $occupations); if (!is_array($occ)) $occ = []; @endphp
                        @foreach($occ as $o)
                            @php $o = (array) $o; @endphp
                            <option value="{{ $o['id'] ?? '' }}">{{ $o['name'] ?? $o['title'] ?? $o['id'] }}</option>
                        @endforeach
                    </select>
                    @error('occupation_id')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="city_id" class="block text-sm font-medium text-slate-700 mb-1">City</label>
                    <select name="city_id" id="city_id"
                        class="w-full rounded-lg border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
                        <option value="">Select…</option>
                        @php $cities = $cities; if (!is_array($cities)) $cities = []; @endphp
                        @foreach($cities as $c)
                            @php $c = (array) $c; @endphp
                            <option value="{{ $c['name'] ?? '' }}">{{ $c['name'] ?? '' }}</option>
                        @endforeach
                    </select>
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
                </div>
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
                        @php $sess = data_get($sessions, 'data.exam_sessions', $sessions); if (!is_array($sess)) $sess = []; @endphp
                        @foreach($sess as $s)
                            @php $s = (array) $s; @endphp
                            <option value="{{ $s['id'] ?? '' }}">{{ $s['name'] ?? $s['title'] ?? ('Session #'.$s['id']) }}</option>
                        @endforeach
                    </select>
                    @error('exam_session_id')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="exam_date" class="block text-sm font-medium text-slate-700 mb-1">Exam Date</label>
                    <input type="date" name="exam_date" id="exam_date" required
                        class="w-full rounded-lg border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
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
@endsection
