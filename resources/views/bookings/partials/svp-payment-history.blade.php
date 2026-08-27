{{-- Read-only payment history returned by the authenticated SVP account. --}}
<div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden mt-8">
    <div class="px-6 py-4 border-b border-slate-100 flex flex-col lg:flex-row lg:items-center justify-between gap-3">
        <div>
            <h2 class="text-base font-semibold text-slate-800">SVP Payment History</h2>
            <p class="text-xs text-slate-400 mt-1">Read-only transactions returned by your authenticated SVP account.</p>
        </div>
        @if ($hasSvpToken)
            <span class="inline-flex items-center gap-1.5 text-xs text-emerald-700">
                <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                Live SVP data
            </span>
        @endif
    </div>

    @if ($svpPaymentError)
        <div class="m-5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            {{ $svpPaymentError }}
            @if (! $hasSvpToken)
                <a href="{{ route('svp.login.form') }}" class="ml-2 underline font-semibold">Sign in with SVP</a>
            @endif
        </div>
    @else
        <form method="GET" action="{{ url()->current() }}" class="px-6 py-4 border-b border-slate-100 grid grid-cols-1 md:grid-cols-[minmax(0,1fr)_180px_auto] gap-3">
            <input type="text" name="payment_search" value="{{ $paymentSearch }}" placeholder="Search transaction, reference or payment ID…"
                   class="w-full rounded-xl border border-slate-200 text-sm text-slate-700 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/30 focus:border-indigo-400 transition">
            <select name="payment_status" class="w-full rounded-xl border border-slate-200 text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-500/30 focus:border-indigo-400 transition">
                <option value="all" {{ $paymentStatus === 'all' ? 'selected' : '' }}>All statuses</option>
                <option value="paid" {{ $paymentStatus === 'paid' ? 'selected' : '' }}>Paid</option>
                <option value="pending" {{ $paymentStatus === 'pending' ? 'selected' : '' }}>Pending</option>
                <option value="failed" {{ $paymentStatus === 'failed' ? 'selected' : '' }}>Failed</option>
                <option value="cancelled" {{ $paymentStatus === 'cancelled' ? 'selected' : '' }}>Cancelled</option>
                <option value="refunded" {{ $paymentStatus === 'refunded' ? 'selected' : '' }}>Refunded</option>
            </select>
            <button type="submit" class="px-4 py-2.5 rounded-xl bg-slate-900 text-white text-sm font-medium hover:bg-slate-800 transition">Filter</button>
        </form>

        <div class="px-6 py-3 border-b border-slate-100 flex items-center justify-between">
            <p class="text-xs text-slate-500">{{ count($svpPayments) }} transaction{{ count($svpPayments) === 1 ? '' : 's' }} found</p>
            <span class="text-[11px] text-slate-400">Filters apply to this read-only SVP response</span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50/70">
                    <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500">
                        <th class="px-6 py-3 font-medium">Payment</th>
                        <th class="px-6 py-3 font-medium">Reference / Payable</th>
                        <th class="px-6 py-3 font-medium">Amount</th>
                        <th class="px-6 py-3 font-medium">Method</th>
                        <th class="px-6 py-3 font-medium">Status</th>
                        <th class="px-6 py-3 text-right font-medium">Date</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($svpPayments as $payment)
                        @php
                            $payment = (array) $payment;
                            $statusStyles = [
                                'paid'      => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                                'pending'   => 'bg-amber-50 text-amber-700 border-amber-200',
                                'failed'    => 'bg-red-50 text-red-700 border-red-200',
                                'cancelled' => 'bg-slate-100 text-slate-600 border-slate-200',
                                'refunded'  => 'bg-purple-50 text-purple-700 border-purple-200',
                            ];
                            $status = (string) ($payment['status'] ?? 'pending');
                            $statusStyle = $statusStyles[$status] ?? 'bg-slate-50 text-slate-700 border-slate-200';
                            $date = $payment['created_at'] ?? null;
                            $dateLabel = is_scalar($date) && $date !== '' ? (string) $date : '—';
                        @endphp
                        <tr class="hover:bg-slate-50/60 transition align-top">
                            <td class="px-6 py-4">
                                <div class="font-mono text-xs font-semibold text-slate-700">{{ $payment['id'] ?? '—' }}</div>
                                @if (! empty($payment['transaction_id']))
                                    <div class="text-[11px] text-slate-400 mt-1 break-all">{{ $payment['transaction_id'] }}</div>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-xs text-slate-600">
                                <div class="break-all">{{ $payment['reference'] ?? '—' }}</div>
                                <div class="text-[11px] text-slate-400 mt-1">{{ $payment['payable_type'] ?? 'Payment' }} #{{ $payment['payable_id'] ?? '—' }}</div>
                            </td>
                            <td class="px-6 py-4 text-xs font-semibold text-slate-700 whitespace-nowrap">
                                {{ is_numeric($payment['amount'] ?? null) ? number_format((float) $payment['amount'], 2) : ($payment['amount'] ?? '—') }}
                                <span class="text-[11px] font-medium text-slate-400">BDT</span>
                            </td>
                            <td class="px-6 py-4 text-xs text-slate-600">{{ $payment['method'] ?? '—' }}</td>
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium border {{ $statusStyle }}">{{ ucfirst($status) }}</span>
                                @if (! empty($payment['result_code']))
                                    <div class="text-[11px] text-slate-400 mt-1">Code: {{ $payment['result_code'] }}</div>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-right text-xs text-slate-500 whitespace-nowrap">{{ $dateLabel }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center">
                                <p class="text-sm font-medium text-slate-600">No SVP payments found</p>
                                <p class="text-xs text-slate-400 mt-1">Try another status or search term.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</div>
