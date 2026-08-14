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
                <dd class="mt-1 font-semibold text-slate-900">{{ $booking->test_center_name }} <span class="font-normal text-slate-500">(SVP ID: {{ $booking->test_center_id }})</span></dd>
            </div>
            <div>
                <dt class="text-slate-400">Session date</dt>
                <dd class="mt-1 font-semibold text-slate-900">{{ $booking->exam_date }}</dd>
            </div>
        </dl>

        <div class="mx-6 mb-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            Opening the checkout is not a payment confirmation. The charge can be completed only on SVP's official, secure payment page.
        </div>

        @if (session('svp_reservation_check'))
            <div class="mx-6 mb-6 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3">
                <p class="text-sm font-semibold text-sky-900">Latest read-only SVP reservation response</p>
                <pre class="mt-2 overflow-x-auto whitespace-pre-wrap text-xs text-sky-950">{{ session('svp_reservation_check') }}</pre>
            </div>
        @endif

        <div class="flex flex-col sm:flex-row gap-3 p-6 bg-slate-50 border-t border-slate-100">
            <a href="{{ $checkoutUrl }}" target="_blank" rel="noopener noreferrer" class="inline-flex justify-center items-center px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold transition">
                Open official SVP card-payment page
            </a>
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
