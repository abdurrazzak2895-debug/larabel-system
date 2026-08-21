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
                <select name="category_id" class="mt-1 w-full rounded-xl border-slate-300" required>
                    <option value="">Select category</option>
                    @foreach (($categories['data'] ?? $categories['categories'] ?? []) as $category)
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
@endsection
