@php
    $mode = $mode ?? 'centers';
    $componentId = $componentId ?? 'pacc-availability-response';
    $centerSelectId = $centerSelectId ?? 'test_center_id';
    $centerNameInputId = $centerNameInputId ?? 'test_center_name';
    $sessionSelectId = $sessionSelectId ?? 'exam_session_id';
    $sessionNameInputId = $sessionNameInputId ?? 'exam_session_name';
    $dateInputId = $dateInputId ?? 'exam_date';
    $hidePanel = $hidePanel ?? false;
@endphp

@once
    <style>
        .pacc-response-card {
            transition: border-color .15s ease, box-shadow .15s ease, background-color .15s ease, transform .15s ease;
        }
        .pacc-response-card:hover {
            transform: translateY(-1px);
        }
        .pacc-response-card[data-selected="1"] {
            border-color: rgb(99 102 241);
            background: rgb(238 242 255);
            box-shadow: 0 0 0 2px rgb(199 210 254), 0 8px 18px -10px rgb(79 70 229 / .45);
        }
    </style>
    <script>
    (() => {
        if (window.PaccAvailabilityComponent) return;

        const esc = value => String(value ?? '').replace(/[&<>"']/g, character => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
        })[character]);
        const normalizeDate = value => String(value ?? '').substring(0, 10);
        const formatTime = value => {
            const raw = String(value ?? '').trim();
            if (!raw || /^\d{4}-\d{2}-\d{2}$/.test(raw)) return '';
            const normalized = raw.replace(/^\d{4}-\d{2}-\d{2}[T ]/, '').replace(/(?:Z|[+-]\d{2}:?\d{2})$/, '').trim();
            return /^\d{4}-\d{2}-\d{2}$/.test(normalized) ? '' : normalized;
        };
        const centerId = row => String(row?.test_center_id ?? row?.site_id ?? row?.center_id ?? row?.id ?? '');
        const centerName = row => String(row?.test_center_name || row?.name || row?.site_name || row?.center_name || 'Live test center').trim();
        const centerSessionId = row => String(row?.exam_session_id ?? row?.session_id ?? row?.sessionId ?? '');
        const centerDate = (row, fallback = '') => normalizeDate(row?.exam_date || row?.test_date || row?.date || row?.start_date_in_tc_time_zone || row?.start_date_in_browser_time_zone || fallback);
        const centerTime = row => formatTime(row?.test_time || row?.start_time || row?.time || row?.start_at_in_tc_time_zone || row?.start_at_in_browser_time_zone || row?.start_at || row?.start_date_in_tc_time_zone || row?.start_date_in_browser_time_zone);
        const centerSeats = row => {
            const value = row?.available_seats ?? row?.availableSeats ?? row?.remaining_seats ?? row?.remainingSeats ?? row?.seats_available ?? row?.available_seat_count ?? row?.seat_count ?? row?.seats ?? null;
            return value === null || value === '' || Number.isNaN(Number(value)) ? null : Number(value);
        };
        const centerSessionCount = row => {
            const value = row?.session_count ?? row?.sessionCount ?? row?.sessions_count ?? null;
            return value === null || value === '' || Number.isNaN(Number(value)) ? null : Number(value);
        };
        const sessionId = row => String(row?.id ?? row?.exam_session_id ?? row?.session_id ?? '');
        const sessionName = (row, index) => String(row?.session_name || row?.name || row?.label || row?.title || '').trim() || `Session ${index + 1}`;
        const sessionDate = row => normalizeDate(row?.exam_date || row?.test_date || row?.date || row?.start_date_in_browser_time_zone || row?.start_date_in_tc_time_zone);
        const sessionTime = row => formatTime(row?.test_time || row?.start_time || row?.time || row?.start_at_in_tc_time_zone || row?.start_at_in_browser_time_zone || row?.start_at || row?.start_date_in_tc_time_zone || row?.start_date_in_browser_time_zone);
        const sessionSeats = row => {
            const value = row?.available_seats ?? row?.availableSeats ?? row?.remaining_seats ?? row?.remainingSeats ?? row?.seats_available ?? row?.available_seat_count ?? row?.seat_count ?? row?.seats ?? null;
            return value === null || value === '' || Number.isNaN(Number(value)) ? null : Number(value);
        };
        const sessionPriority = row => {
            const explicit = [row?.priority, row?.session_priority, row?.session_order, row?.shift_number, row?.sequence, row?.sort_order]
                .map(value => Number(value))
                .find(value => Number.isFinite(value));
            if (explicit !== undefined) return explicit;
            const label = String(row?.session_name || row?.name || row?.label || row?.title || '').toLowerCase();
            const named = ['first', 'second', 'third', 'fourth', 'fifth', 'sixth', 'seventh', 'eighth', 'ninth', 'tenth'];
            const namedIndex = named.findIndex(word => label.includes(word + ' shift'));
            if (namedIndex >= 0) return namedIndex + 1;
            const numbered = label.match(/\bshift\s*(\d+)\b/);
            return numbered ? Number(numbered[1]) : Number.MAX_SAFE_INTEGER;
        };
        const sessionSortTime = row => {
            const value = sessionTime(row).match(/(\d{1,2})(?::(\d{2}))?\s*(AM|PM)?/i);
            if (!value) return Number.MAX_SAFE_INTEGER;
            let hours = Number(value[1]);
            const minutes = Number(value[2] || 0);
            const meridiem = String(value[3] || '').toUpperCase();
            if (meridiem === 'PM' && hours < 12) hours += 12;
            if (meridiem === 'AM' && hours === 12) hours = 0;
            return hours * 60 + minutes;
        };
        const prioritizeSessions = items => items.map((row, index) => ({row, index})).sort((left, right) => {
            return sessionPriority(left.row) - sessionPriority(right.row)
                || sessionSortTime(left.row) - sessionSortTime(right.row)
                || left.index - right.index;
        });

        function create(root, options = {}) {
            const mode = options.mode || 'centers';
            const panel = root.querySelector('[data-pacc-panel]');
            const status = root.querySelector('[data-pacc-status]');
            const list = root.querySelector('[data-pacc-list]');
            const centerSelect = document.getElementById(options.centerSelectId || 'test_center_id');
            const centerNameInput = document.getElementById(options.centerNameInputId || 'test_center_name');
            const sessionSelect = document.getElementById(options.sessionSelectId || 'exam_session_id');
            const sessionNameInput = document.getElementById(options.sessionNameInputId || 'exam_session_name');
            const dateInput = document.getElementById(options.dateInputId || 'exam_date');
            let selectedCardKey = '';

            function setVisible(visible) {
                panel?.classList.toggle('hidden', !visible);
            }

            function syncSelection() {
                list?.querySelectorAll('[data-pacc-value]').forEach(card => {
                    card.dataset.selected = card.dataset.paccIndex === selectedCardKey ? '1' : '0';
                });
            }

            function empty(message) {
                setVisible(true);
                if (status) status.textContent = message;
                if (list) list.innerHTML = `<div class="rounded-xl border border-amber-200 bg-amber-50 px-3 py-3 text-xs text-amber-800">${esc(message)}</div>`;
            }

            function renderCenters(rows, meta = {}) {
                const items = Array.isArray(rows) ? rows : [];
                selectedCardKey = '';
                setVisible(true);
                if (!items.length) {
                    empty(meta.emptyText || 'No live test-center slots returned for the selected date.');
                    return items;
                }
                if (status) status.textContent = `${meta.city ? meta.city + ' · ' : ''}${meta.date ? meta.date + ' · ' : ''}${items.length} live center slot${items.length === 1 ? '' : 's'}`;
                if (list) {
                    list.innerHTML = items.map((row, index) => {
                        const seats = centerSeats(row);
                        const sessionCount = centerSessionCount(row);
                        const time = centerTime(row);
                        const id = centerId(row);
                        const exactSessionId = centerSessionId(row);
                        const date = centerDate(row, meta.date);
                        return `<button type="button" class="pacc-response-card w-full rounded-xl border border-slate-200 bg-white p-3 text-left shadow-sm hover:border-indigo-300 hover:bg-indigo-50" data-pacc-value="${esc(id)}" data-pacc-index="${index}" data-pacc-session-id="${esc(exactSessionId)}">
                            <span class="flex items-start justify-between gap-3"><span class="min-w-0"><strong class="block break-words whitespace-normal text-sm text-slate-900">${esc(centerName(row))}</strong><span class="mt-1 block text-[11px] text-slate-500">Center ID: ${esc(id || 'Not provided')}</span></span><span class="shrink-0 rounded-full ${seats !== null && seats <= 3 ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700'} px-2 py-1 text-[11px] font-bold">${esc(seats === null ? 'Seats n/a' : seats + ' seats')}</span></span>
                            <span class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-[11px] text-slate-500"><span>Date: <b class="text-slate-700">${esc(date || 'Not provided')}</b></span><span>Time: <b class="text-slate-700">${esc(time || 'Not provided')}</b></span><span>Sessions: <b class="text-slate-700">${esc(sessionCount === null ? 'Live lookup' : sessionCount)}</b></span><span>Available slot ${index + 1}</span></span>
                            <span class="mt-1 block break-all text-[10px] text-slate-500">Session ID: <b class="font-mono text-slate-700">${esc(exactSessionId || 'Select to load exact SVP sessions')}</b></span>
                        </button>`;
                    }).join('');
                    list.querySelectorAll('[data-pacc-index]').forEach(card => card.addEventListener('click', () => {
                        if (!centerSelect) return;
                        selectedCardKey = String(card.dataset.paccIndex || '');
                        const selectedRow = items[Number(card.dataset.paccIndex)] || {};
                        const selectedCenterId = String(centerId(selectedRow));
                        const selectedCenterName = centerName(selectedRow);
                        centerSelect.value = selectedCenterId;
                        centerSelect.dataset.name = selectedCenterName;
                        // A Portal Availability row may contain a session ID
                        // from a different credential. Never submit that opaque
                        // ID directly. The owning booking wizard must load the
                        // exact center-scoped sessions using the candidate SVP
                        // token before the user can create a hold.
                        centerSelect.dataset.sessionId = '';
                        centerSelect.dataset.sessionDate = '';
                        if (centerNameInput) {
                            centerNameInput.value = selectedCenterName;
                            centerNameInput.dataset.centerId = selectedCenterId;
                        }
                        centerSelect.dispatchEvent(new Event('change', {bubbles: true}));
                        syncSelection();
                    }));
                }
                syncSelection();
                return items;
            }

            function renderSessions(rows, meta = {}) {
                const items = Array.isArray(rows) ? rows : [];
                selectedCardKey = '';
                setVisible(true);
                if (!items.length) {
                    empty(meta.emptyText || 'No exact SVP sessions returned for the selected center and date.');
                    return items;
                }
                if (status) status.textContent = `${meta.date ? 'Sessions for ' + meta.date + ' · ' : ''}${items.length} exact SVP session${items.length === 1 ? '' : 's'}`;
                if (list) {
                    const prioritized = prioritizeSessions(items);
                    list.innerHTML = prioritized.map((entry, displayIndex) => {
                        const row = entry.row;
                        const index = entry.index;
                        const id = sessionId(row);
                        const time = sessionTime(row);
                        const seats = sessionSeats(row);
                        const date = sessionDate(row) || meta.date || 'Date unavailable';
                        return `<button type="button" class="pacc-response-card w-full rounded-xl border border-slate-200 bg-white p-3 text-left shadow-sm hover:border-indigo-300 hover:bg-indigo-50" data-pacc-value="${esc(id)}" data-pacc-index="${index}">
                            <span class="flex items-start justify-between gap-3"><span class="min-w-0"><strong class="block text-sm text-slate-900">${esc(sessionName(row, displayIndex))}</strong><span class="mt-1 block text-[11px] text-slate-500">${esc(date)}</span><span class="mt-1 block break-all text-[10px] text-slate-500">Session ID: <b class="font-mono text-slate-700">${esc(id || 'Not provided')}</b></span></span><span class="shrink-0 rounded-full ${seats !== null && seats <= 3 ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700'} px-2 py-1 text-[11px] font-bold">${esc(seats === null ? 'Seats n/a' : seats + ' seats')}</span></span>
                            <span class="mt-2 block text-[11px] text-slate-500">Time: <b class="text-slate-700">${esc(time || 'Not provided')}</b></span>
                        </button>`;
                    }).join('');
                    list.querySelectorAll('[data-pacc-value]').forEach(card => card.addEventListener('click', () => {
                        if (!sessionSelect) return;
                        selectedCardKey = String(card.dataset.paccIndex || '');
                        const index = Number(card.dataset.paccIndex || 0);
                        const selectedRow = items[index] || {};
                        const selectedId = card.dataset.paccValue || '';
                        sessionSelect.value = selectedId;
                        sessionSelect.dataset.name = sessionName(selectedRow, index);
                        sessionSelect.dataset.date = sessionDate(selectedRow) || meta.date || '';
                        if (sessionNameInput) sessionNameInput.value = sessionSelect.dataset.name;
                        if (dateInput) dateInput.value = sessionSelect.dataset.date;
                        sessionSelect.dispatchEvent(new Event('change', {bubbles: true}));
                        syncSelection();
                    }));
                }
                syncSelection();
                return items;
            }

            function clear() {
                selectedCardKey = '';
                if (list) list.innerHTML = '';
                if (status) status.textContent = '';
                setVisible(false);
            }

            centerSelect?.addEventListener('change', syncSelection);
            sessionSelect?.addEventListener('change', syncSelection);
            clear();

            return {renderCenters, renderSessions, clear, syncSelection};
        }

        window.PaccAvailabilityComponent = {create};
    })();
    </script>
@endonce

<div id="{{ $componentId }}" class="{{ $hidePanel ? 'hidden ' : '' }}mt-3" data-pacc-availability-response data-pacc-mode="{{ $mode }}" @if($hidePanel) aria-hidden="true" @endif>
    <div data-pacc-panel class="hidden rounded-xl border border-slate-200 bg-slate-50 p-3">
        <div class="mb-2 flex items-center justify-between gap-3">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">{{ $mode === 'centers' ? 'Live center slots' : 'Live verified sessions' }}</p>
            <span data-pacc-status class="text-[11px] text-slate-500"></span>
        </div>
        <div data-pacc-list class="grid grid-cols-1 gap-2"></div>
    </div>
</div>
<script>
(() => {
    window.PaccAvailabilityInstances = window.PaccAvailabilityInstances || {};
    window.PaccAvailabilityInstances['{{ $componentId }}'] = window.PaccAvailabilityComponent.create(
        document.getElementById('{{ $componentId }}'),
        {mode: '{{ $mode }}', centerSelectId: '{{ $centerSelectId }}', centerNameInputId: '{{ $centerNameInputId }}', sessionSelectId: '{{ $sessionSelectId }}', sessionNameInputId: '{{ $sessionNameInputId }}', dateInputId: '{{ $dateInputId }}'}
    );
})();
</script>
