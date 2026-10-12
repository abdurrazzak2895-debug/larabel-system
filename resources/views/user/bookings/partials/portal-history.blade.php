{{-- Portal booking outcome history --}}
<div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
        <div>
            <h2 class="text-base font-semibold text-slate-800">Portal Booking History</h2>
            <p class="text-xs text-slate-400 mt-1">Your agency SVP booking attempts, failures, and refund outcomes.</p>
        </div>
        <span class="text-xs text-slate-500">{{ $localBookings->total() }} record{{ $localBookings->total() === 1 ? '' : 's' }}</span>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50/70 text-left text-[11px] uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-6 py-3 font-medium">Booking</th>
                    <th class="px-6 py-3 font-medium">Status</th>
                    <th class="px-6 py-3 font-medium">Fee</th>
                    <th class="px-6 py-3 font-medium">Refund outcome</th>
                    <th class="px-6 py-3 font-medium">Failure / activity</th>
                    <th class="px-6 py-3 text-right font-medium">Created</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($localBookings as $booking)
                    @php
                        $latestAttempt = $booking->attempts->sortByDesc('id')->first();
                        $latestLog = $booking->logs->sortByDesc('id')->first();
                        $latestRefund = $booking->refundRequests->sortByDesc('id')->first();
                        $status = (string) $booking->booking_status;
                        $statusStyles = [
                            'booked' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                            'pending' => 'bg-amber-50 text-amber-700 border-amber-200',
                            'processing' => 'bg-blue-50 text-blue-700 border-blue-200',
                            'failed' => 'bg-red-50 text-red-700 border-red-200',
                            'cancelled' => 'bg-slate-100 text-slate-600 border-slate-200',
                            'refunded' => 'bg-purple-50 text-purple-700 border-purple-200',
                        ];
                        $statusClass = $statusStyles[$status] ?? 'bg-slate-50 text-slate-700 border-slate-200';
                        $failureMessage = $latestAttempt?->error_message
                            ?: data_get($latestLog?->payload, 'error_message')
                            ?: data_get($latestLog?->payload, 'error')
                            ?: ($latestLog?->event_type === 'booking_failed' ? 'Booking failed at the SVP reservation step.' : null);
                        $refundLabel = $latestRefund
                            ? ucfirst((string) $latestRefund->status)
                            : ($status === 'refunded' ? 'Refunded' : (($status === 'failed' || $status === 'cancelled') ? 'Not refunded' : '—'));
                        $refundClass = $refundLabel === 'Refunded' || $refundLabel === 'Processed'
                            ? 'text-emerald-700 bg-emerald-50 border-emerald-200'
                            : ($refundLabel === 'Not refunded' ? 'text-slate-600 bg-slate-100 border-slate-200' : 'text-slate-500 bg-slate-50 border-slate-200');
                    @endphp
                    <tr class="hover:bg-slate-50/50 transition align-top">
                        <td class="px-6 py-4">
                            <a href="{{ route('user.bookings.show', $booking) }}" class="font-mono text-xs font-semibold text-indigo-600 hover:text-indigo-800">
                                {{ $booking->booking_reference ?: '#'.$booking->id }}
                            </a>
                            <span class="block text-[11px] text-slate-400 mt-1">{{ $booking->credential?->full_name ?: 'SVP account' }}</span>
                            @if (in_array($status, ['failed', 'cancelled'], true) && (float) ($booking->portal_booking_fee ?? 0) > 0 && (! $latestRefund || $latestRefund->status === 'rejected'))
                                <a href="{{ route('user.refunds.create', ['booking' => $booking->id]) }}" class="inline-flex mt-2 items-center px-2.5 py-1.5 rounded-lg bg-indigo-600 text-white text-[11px] font-semibold hover:bg-indigo-700 transition">Request Refund</a>
                            @endif
                        </td>
                        <td class="px-6 py-4">
                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold border {{ $statusClass }}">{{ ucfirst($status) }}</span>
                        </td>
                        <td class="px-6 py-4 text-xs text-slate-700 whitespace-nowrap">
                            {{ number_format((float) ($booking->portal_booking_fee ?? 0), 2) }} BDT
                        </td>
                        <td class="px-6 py-4">
                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium border {{ $refundClass }}">{{ $refundLabel }}</span>
                            @if ($latestRefund)
                                <span class="block text-[11px] text-slate-400 mt-1">{{ number_format((float) $latestRefund->amount, 2) }} BDT</span>
                            @elseif (in_array($status, ['failed', 'cancelled'], true))
                                <span class="block text-[11px] text-slate-400 mt-1">Portal fee policy</span>
                            @endif
                        </td>
                        <td class="px-6 py-4 text-xs text-slate-600 max-w-[280px]">
                            {{ $failureMessage ?: ($latestLog?->event_type ? str_replace('_', ' ', ucfirst($latestLog->event_type)) : 'No failure recorded') }}
                        </td>
                        <td class="px-6 py-4 text-right text-xs text-slate-500 whitespace-nowrap">{{ $booking->created_at?->format('d M Y H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-6 py-10 text-center text-sm text-slate-400">No portal booking history yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($localBookings->hasPages())
        <div class="px-6 py-3 border-t border-slate-100">{{ $localBookings->links() }}</div>
    @endif
</div>
