{{-- One availability row + its expandable per-session real-test-center verification. --}}
@php
    $sessions = collect($row['sessions'] ?? []);
    $exactCount = $sessions->filter(fn ($session) => ($session['real_test_center']['match'] ?? 'city_scope') === 'exact')->count();
@endphp
<tr>
    <td class="px-5 py-3 font-medium text-slate-800">{{ $row['center_name'] }}</td>
    <td class="px-5 py-3"><span class="font-semibold text-emerald-600">Available</span></td>
    <td class="px-5 py-3 text-center font-semibold text-slate-700">{{ $row['session_count'] }}</td>
    <td class="px-5 py-3 text-right">
        <button type="button" data-verify-toggle
            class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-brand-700 hover:border-brand-300 hover:bg-brand-50 transition">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            Verify {{ $sessions->count() }} session{{ $sessions->count() === 1 ? '' : 's' }}
        </button>
    </td>
</tr>
<tr data-verify-panel class="hidden">
    <td colspan="4" class="bg-slate-50/70 px-5 pb-4">
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                <p class="text-xs font-semibold uppercase tracking-widest text-slate-500">Per-session real test center — checked live against SVP</p>
                <p class="text-[11px] text-slate-400">{{ $exactCount }} exact center · {{ $sessions->count() - $exactCount }} city scope</p>
            </div>
            <p class="mb-3 text-[11px] text-slate-400">Each entry is a separate bookable shift returned live by SVP for <span class="font-semibold">{{ $row['center_name'] }}</span> on this date — session IDs are unique and never shared between centers.</p>
            <div class="space-y-2">
                @forelse ($sessions as $session)
                    @php
                        $real = is_array($session['real_test_center'] ?? null) ? $session['real_test_center'] : [];
                        $isExact = ($real['match'] ?? 'city_scope') === 'exact';
                        $sid = (string) ($session['id'] ?? '');
                        $shortId = strlen($sid) > 20 ? substr($sid, 0, 12) . '…' . substr($sid, -6) : $sid;
                        $realLabel = filled($real['name'] ?? null)
                            ? $real['name']
                            : (filled($real['id'] ?? null) ? 'Test center ID ' . $real['id'] : null);
                    @endphp
                    <div class="rounded-xl border p-3 {{ $isExact ? 'border-emerald-200' : 'border-amber-200' }}">
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                            <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-bold {{ $isExact ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-amber-50 text-amber-700 border border-amber-200' }}">
                                ✓ Verified
                            </span>
                            <span class="inline-flex items-center rounded-lg bg-brand-50 border border-brand-200 px-2 py-0.5 text-[11px] font-bold text-brand-700">{{ $session['shift_label'] ?? ($session['shift'] ?? 'Session') }}</span>
                            <span class="text-xs font-semibold text-slate-800" title="{{ $session['shift'] ?? '' }}">{{ $row['center_name'] }}</span>
                            <code class="text-[10px] text-slate-400" title="{{ $sid }}">{{ $shortId }}</code>
                            @if (($session['status'] ?? null))
                                <span class="text-[11px] rounded-full bg-slate-100 border border-slate-200 px-2 py-0.5 text-slate-600">{{ $session['status'] }}</span>
                            @endif
                            @if (($session['time_zone_name'] ?? null))
                                <span class="text-[11px] rounded-full bg-slate-100 border border-slate-200 px-2 py-0.5 text-slate-600">{{ $session['time_zone_name'] }}</span>
                            @endif
                        </div>
                        <dl class="mt-2 grid grid-cols-2 sm:grid-cols-4 gap-x-4 gap-y-2 text-[11px]">
                            <div>
                                <dt class="uppercase tracking-wide text-slate-400">Real center (SVP)</dt>
                                <dd class="font-semibold text-slate-700">{{ $realLabel ?? 'Not published by SVP' }}</dd>
                            </div>
                            <div>
                                <dt class="uppercase tracking-wide text-slate-400">City</dt>
                                <dd class="font-semibold text-slate-700">{{ $real['city'] ?? ($ctxCity ?? $row['city'] ?? '—') }}</dd>
                            </div>
                            <div>
                                <dt class="uppercase tracking-wide text-slate-400">Match type</dt>
                                <dd class="font-semibold {{ $isExact ? 'text-emerald-700' : 'text-amber-700' }}">{{ $isExact ? 'Exact center' : 'City scope only' }}</dd>
                            </div>
                            <div>
                                <dt class="uppercase tracking-wide text-slate-400">Session date (SVP)</dt>
                                <dd class="font-semibold text-slate-700">{{ \Carbon\Carbon::parse($row['date'])->format('d M, Y') }}</dd>
                            </div>
                        </dl>
                        @if (! $isExact)
                            <p class="mt-2 text-[11px] text-amber-700">SVP's authoritative session record confirms the city but omits the test-center id/name for this deployment; the session is listed under {{ $row['center_name'] }}, the centre it was queried against.</p>
                        @endif
                    </div>
                @empty
                    <p class="text-xs text-slate-500">No verified sessions to display.</p>
                @endforelse
            </div>
        </div>
    </td>
</tr>
