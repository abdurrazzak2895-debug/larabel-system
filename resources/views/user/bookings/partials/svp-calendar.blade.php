{{-- Reusable Cal.com-style availability calendar. --}}
{{-- Mount: SvpCalendar.create('element-id', { onSelect(date), emptyText }) --}}
@once
<style>
    .svp-cal-day {
        position: relative;
        height: 2.35rem;
        border-radius: 0.75rem;
        font-size: 0.8125rem;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.15s ease;
        user-select: none;
    }
    .svp-cal-day[data-state="available"] {
        background: #fee2e2;
        color: #b91c1c;
        font-weight: 700;
        cursor: pointer;
        box-shadow: inset 0 0 0 1px #fecaca;
    }
    .svp-cal-day[data-state="available"]:hover {
        background: #fecaca;
        transform: translateY(-1px);
    }
    .svp-cal-day[data-state="selected"] {
        background: linear-gradient(135deg, #dc2626, #f97316);
        color: #fff;
        font-weight: 700;
        cursor: pointer;
        box-shadow: 0 8px 18px -6px rgba(99, 102, 241, 0.55);
        transform: translateY(-1px);
    }
    .svp-cal-day[data-state="today"] {
        color: #94a3b8;
        box-shadow: inset 0 0 0 1px #cbd5e1;
        cursor: default;
    }
    .svp-cal-day[data-state="selected"][data-today="1"] {
        box-shadow: 0 8px 18px -6px rgba(99, 102, 241, 0.55), inset 0 0 0 1px rgba(255, 255, 255, 0.55);
    }
    .svp-cal-day[data-state="muted"] {
        color: #cbd5e1;
        cursor: default;
    }
</style>
@endonce

<div id="{{ $calendarId }}" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
    <div class="flex items-center justify-between gap-2 mb-3">
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-widest text-brand-600">Available exam dates</p>
            <p data-calendar-month class="text-base font-bold text-slate-900 truncate">—</p>
        </div>
        <div class="flex items-center gap-1.5 shrink-0">
            <button type="button" data-calendar-prev aria-label="Previous month"
                class="w-8 h-8 rounded-xl border border-slate-200 bg-white text-slate-500 hover:text-brand-700 hover:border-brand-300 flex items-center justify-center transition disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:border-slate-200 disabled:hover:text-slate-500">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            </button>
            <button type="button" data-calendar-next aria-label="Next month"
                class="w-8 h-8 rounded-xl border border-slate-200 bg-white text-slate-500 hover:text-brand-700 hover:border-brand-300 flex items-center justify-center transition disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:border-slate-200 disabled:hover:text-slate-500">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            </button>
        </div>
    </div>

    <div class="grid grid-cols-7 gap-1 mb-1 text-center">
        @foreach (['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'] as $weekdayLabel)
            <span class="text-[10px] font-semibold uppercase tracking-wider text-slate-400">{{ $weekdayLabel }}</span>
        @endforeach
    </div>

    <div data-calendar-grid class="grid grid-cols-7 gap-1"></div>

    <div class="mt-3 pt-3 border-t border-slate-100 flex flex-wrap items-center gap-x-4 gap-y-1.5">
        <span class="inline-flex items-center gap-1.5 text-[11px] text-slate-400"><span class="w-2.5 h-2.5 rounded-full bg-gradient-to-br from-indigo-500 to-fuchsia-500"></span>Selected</span>
        <span class="inline-flex items-center gap-1.5 text-[11px] text-slate-400"><span class="w-2.5 h-2.5 rounded-full bg-red-100 ring-1 ring-red-200"></span>Available</span>
        <span data-calendar-count class="ml-auto text-[11px] font-medium text-slate-400"></span>
    </div>
</div>

<script>
(() => {
    if (window.SvpCalendar) return;

    const pad = value => String(value).padStart(2, '0');
    const MONTH_NAMES = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

    window.SvpCalendar = {
        create(target, options = {}) {
            const root = typeof target === 'string' ? document.getElementById(target) : target;
            if (!root) return null;

            const grid = root.querySelector('[data-calendar-grid]');
            const monthLabel = root.querySelector('[data-calendar-month]');
            const prevButton = root.querySelector('[data-calendar-prev]');
            const nextButton = root.querySelector('[data-calendar-next]');
            const countLabel = root.querySelector('[data-calendar-count]');

            const availableDates = new Set();
            let viewYear = null;
            let viewMonth = null;
            let selectedDate = '';

            const todayKey = (() => {
                const now = new Date();
                return now.getFullYear() + '-' + pad(now.getMonth() + 1) + '-' + pad(now.getDate());
            })();

            const normalize = value => String(value ?? '').substring(0, 10);

            function setViewToFirstAvailable() {
                if (viewYear !== null) return;
                const first = Array.from(availableDates).sort()[0];
                if (first) {
                    const [year, month] = first.split('-').map(Number);
                    viewYear = year;
                    viewMonth = month - 1;
                } else {
                    const now = new Date();
                    viewYear = now.getFullYear();
                    viewMonth = now.getMonth();
                }
            }

            function shiftMonth(delta) {
                if (viewYear === null) return;
                let month = viewMonth + delta;
                let year = viewYear;
                while (month < 0) { month += 12; year -= 1; }
                while (month > 11) { month -= 12; year += 1; }
                viewYear = year;
                viewMonth = month;
                render();
            }

            function renderDayCell(dayKey, dayNumber, inMonth, isAvailable) {
                const cell = document.createElement(isAvailable ? 'button' : 'span');
                cell.type = 'button';
                cell.className = 'svp-cal-day';
                cell.textContent = dayNumber;
                cell.dataset.today = dayKey === todayKey ? '1' : '0';

                if (!inMonth) {
                    cell.dataset.state = 'muted';
                    cell.setAttribute('aria-hidden', 'true');
                } else if (isAvailable) {
                    cell.dataset.state = dayKey === selectedDate ? 'selected' : 'available';
                    cell.dataset.date = dayKey;
                    cell.title = 'Available — ' + dayKey;
                    if (dayKey === selectedDate) cell.setAttribute('aria-current', 'date');
                } else {
                    cell.dataset.state = dayKey === todayKey ? 'today' : 'muted';
                    if (dayKey === todayKey) cell.title = 'Today — no availability yet';
                }

                return cell;
            }

            function render() {
                grid.innerHTML = '';
                if (viewYear === null) {
                    monthLabel.textContent = '—';
                    countLabel.textContent = options.emptyText || 'No dates loaded yet.';
                    prevButton.disabled = true;
                    nextButton.disabled = true;
                    return;
                }

                monthLabel.textContent = MONTH_NAMES[viewMonth] + ' ' + viewYear;
                countLabel.textContent = availableDates.size
                    ? availableDates.size + (availableDates.size === 1 ? ' date open' : ' dates open')
                    : (options.emptyText || 'No dates returned by SVP.');

                const firstWeekday = new Date(viewYear, viewMonth, 1).getDay();
                const daysInMonth = new Date(viewYear, viewMonth + 1, 0).getDate();

                for (let blank = 0; blank < firstWeekday; blank += 1) {
                    const filler = document.createElement('span');
                    filler.className = 'svp-cal-day';
                    filler.style.visibility = 'hidden';
                    grid.appendChild(filler);
                }

                for (let day = 1; day <= daysInMonth; day += 1) {
                    const key = viewYear + '-' + pad(viewMonth + 1) + '-' + pad(day);
                    grid.appendChild(renderDayCell(key, day, true, availableDates.has(key)));
                }

                const totalCells = firstWeekday + daysInMonth;
                for (let tail = totalCells; tail % 7 !== 0; tail += 1) {
                    const filler = document.createElement('span');
                    filler.className = 'svp-cal-day';
                    filler.style.visibility = 'hidden';
                    grid.appendChild(filler);
                }

                let minMonth = null;
                let maxMonth = null;
                availableDates.forEach(date => {
                    const [year, month] = date.split('-').map(Number);
                    const key = year * 12 + (month - 1);
                    if (minMonth === null || key < minMonth) minMonth = key;
                    if (maxMonth === null || key > maxMonth) maxMonth = key;
                });

                if (minMonth === null) {
                    prevButton.disabled = true;
                    nextButton.disabled = true;
                } else {
                    const current = viewYear * 12 + viewMonth;
                    prevButton.disabled = current <= minMonth;
                    nextButton.disabled = current >= maxMonth;
                }
            }

            prevButton.addEventListener('click', () => shiftMonth(-1));
            nextButton.addEventListener('click', () => shiftMonth(1));

            grid.addEventListener('click', event => {
                const target = event.target.closest('[data-date]');
                if (!target) return;
                const date = target.dataset.date;
                if (date === selectedDate) return;
                selectedDate = date;
                render();
                if (typeof options.onSelect === 'function') options.onSelect(date);
            });

            render();

            return {
                setDates(dates) {
                    availableDates.clear();
                    (Array.isArray(dates) ? dates : []).forEach(date => {
                        const value = normalize(date);
                        if (/^\d{4}-\d{2}-\d{2}$/.test(value)) availableDates.add(value);
                    });
                    setViewToFirstAvailable();
                    if (selectedDate && !availableDates.has(selectedDate)) selectedDate = '';
                    render();
                },
                setSelected(date, silent) {
                    const value = normalize(date);
                    selectedDate = /^\d{4}-\d{2}-\d{2}$/.test(value) && (!availableDates.size || availableDates.has(value)) ? value : '';
                    render();
                    if (!silent && selectedDate && typeof options.onSelect === 'function') options.onSelect(selectedDate);
                },
                getSelected: () => selectedDate,
                hasDate: date => availableDates.has(normalize(date)),
            };
        },
    };
})();
</script>
