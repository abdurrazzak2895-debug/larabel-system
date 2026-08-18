@extends($layout)

@section('title', 'Complete SVP Payment')
@section('page-title', 'Complete SVP Payment')

@section('content')
<div class="max-w-3xl mx-auto">
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="p-6 border-b border-slate-100">
            <p class="text-xs font-semibold tracking-wide uppercase text-slate-400">Official SVP payment</p>
            <h2 class="mt-1 text-2xl font-bold text-slate-900">Complete payment on SVP</h2>
            <p class="mt-2 text-sm leading-6 text-slate-600">SVP created a card checkout because no usable reservation credit was available. This application does not collect or store card information.</p>
        </div>

        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-4 p-6 text-sm">
            <div>
                <dt class="text-slate-400">Booking reference</dt>
                <dd class="mt-1 font-semibold text-slate-900">{{ $booking->booking_reference }}</dd>
            </div>
            <div>
                <dt class="text-slate-400">SVP reservation ID</dt>
                <dd class="mt-1 font-semibold text-slate-900">{{ $booking->reservation_id }}</dd>
            </div>
            <div>
                <dt class="text-slate-400">Selected test center</dt>
                <dd class="mt-1 font-semibold text-slate-900">{{ $booking->test_center_name ?: 'Test center unavailable' }}</dd>
            </div>
            <div>
                <dt class="text-slate-400">Session date</dt>
                <dd class="mt-1 font-semibold text-slate-900">{{ $booking->exam_date }}</dd>
            </div>
        </dl>

        <div class="mx-6 mb-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            The secure card fields below are hosted by HyperPay. Card number, CVV, and expiry are sent directly to HyperPay and are not collected or stored by this application.
        </div>

        @if (session('svp_reservation_check'))
            <div class="mx-6 mb-6 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3">
                <p class="text-sm font-semibold text-sky-900">Latest read-only SVP reservation response</p>
                <pre class="mt-2 overflow-x-auto whitespace-pre-wrap text-xs text-sky-950">{{ session('svp_reservation_check') }}</pre>
            </div>
        @endif

        @if (!empty($widgetCheckoutId))
            <div class="mx-6 mb-6 rounded-2xl border border-slate-200 bg-slate-50 p-5">
                <div class="mb-4">
                    <p class="text-sm font-semibold text-slate-900">Pay securely with HyperPay</p>
                    <p class="mt-1 text-xs leading-5 text-slate-600">This is the official SVP COPYandPAY card form. The payment result will return to this booking automatically.</p>
                </div>
                <form action="{{ $shopperResultUrl }}" class="paymentWidgets" data-brands="VISA MASTER AMEX"></form>
                <script
                    src="{{ rtrim($widgetScriptUrl ?? config('svp.hyperpay_widget_url'), '?') }}?checkoutId={{ rawurlencode($widgetCheckoutId) }}"
                    @if (!empty($widgetIntegrity)) integrity="{{ $widgetIntegrity }}" crossorigin="anonymous" @endif
                ></script>
            </div>
        @endif

        @if (!empty($checkoutUrl))
            <div class="mx-6 mb-6 rounded-xl border border-indigo-100 bg-indigo-50 px-4 py-3">
                <p class="text-sm font-semibold text-indigo-900">Alternative official checkout</p>
                <p class="mt-1 text-xs leading-5 text-indigo-800">Use this transaction-specific fallback only if the embedded HyperPay form is unavailable.</p>
                <a href="{{ $checkoutUrl }}" target="_blank" rel="noopener noreferrer" class="mt-3 inline-flex justify-center items-center px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold transition">
                    Open official SVP card-payment page
                </a>
            </div>
        @endif

        <div class="flex flex-col sm:flex-row gap-3 p-6 bg-slate-50 border-t border-slate-100">
            <form method="POST" action="{{ $verifyRoute }}">
                @csrf
                <button type="submit" class="w-full inline-flex justify-center items-center px-5 py-2.5 rounded-xl bg-white border border-slate-200 hover:bg-slate-100 text-slate-700 text-sm font-semibold transition">
                    Check selected SVP reservation
                </button>
            </form>
            <a href="{{ $backRoute }}" class="inline-flex justify-center items-center px-5 py-2.5 rounded-xl text-slate-600 hover:text-slate-900 text-sm font-medium transition">Return to booking</a>
        </div>
    </div>
</div>
@endsection
