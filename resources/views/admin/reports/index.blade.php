@extends('layouts.panel')

@section('title', 'Reports')
@section('page-title', 'Reports')

@section('content')
{{-- Summary stat cards --}}
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    @php
        $cards = [
            ['label' => 'Total Agencies', 'value' => $agencySummary['total_agencies'] ?? 0, 'sub' => ($agencySummary['active_agencies'] ?? 0) . ' active', 'icon' => 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4', 'color' => 'from-indigo-500 to-blue-500'],
            ['label' => 'Total Users', 'value' => $agencySummary['total_users'] ?? 0, 'sub' => 'across all agencies', 'icon' => 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z', 'color' => 'from-emerald-500 to-teal-500'],
            ['label' => 'Wallet Balance', 'value' => number_format($agencySummary['total_wallet_balance'] ?? 0, 2), 'sub' => 'SAR across wallets', 'icon' => 'M3 10h18M7 15h2m-5-7h16a1 1 0 011 1v10a1 1 0 01-1 1H5a2 2 0 01-2-2V7a2 2 0 012-2z', 'color' => 'from-fuchsia-500 to-purple-500'],
            ['label' => 'Bookings (30d)', 'value' => $bookingStats['total'] ?? 0, 'sub' => ($bookingStats['failed'] ?? 0) . ' failed', 'icon' => 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z', 'color' => 'from-amber-500 to-orange-500'],
        ];
    @endphp
    @foreach ($cards as $c)
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
            <div class="flex items-center gap-4">
                <div class="w-11 h-11 rounded-xl bg-gradient-to-br {{ $c['color'] }} flex items-center justify-center text-white shrink-0">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $c['icon'] }}"/></svg>
                </div>
                <div class="min-w-0">
                    <p class="text-xl font-bold text-slate-900 leading-none truncate">{{ $c['value'] }}</p>
                    <p class="text-xs text-slate-500 mt-1">{{ $c['label'] }}</p>
                </div>
            </div>
            <p class="text-xs text-slate-400 mt-3">{{ $c['sub'] }}</p>
        </div>
    @endforeach
</div>

{{-- Wallet + booking stats --}}
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6">
        <h3 class="text-sm font-semibold text-slate-700 mb-4">Wallet Summary</h3>
        <dl class="space-y-3">
            <div class="flex items-center justify-between">
                <dt class="text-sm text-slate-500">Total Available</dt>
                <dd class="text-sm font-bold text-emerald-600">{{ number_format($walletSummary['total_available'] ?? 0, 2) }} SAR</dd>
            </div>
            <div class="flex items-center justify-between">
                <dt class="text-sm text-slate-500">Total Reserved</dt>
                <dd class="text-sm font-bold text-amber-600">{{ number_format($walletSummary['total_reserved'] ?? 0, 2) }} SAR</dd>
            </div>
            <div class="flex items-center justify-between">
                <dt class="text-sm text-slate-500">Total Credit Limit</dt>
                <dd class="text-sm font-bold text-slate-800">{{ number_format($walletSummary['total_credit_limit'] ?? 0, 2) }} SAR</dd>
            </div>
        </dl>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6">
        <h3 class="text-sm font-semibold text-slate-700 mb-4">Deposits (30d)</h3>
        <dl class="space-y-3">
            <div class="flex items-center justify-between">
                <dt class="text-sm text-slate-500">Total</dt>
                <dd class="text-sm font-bold text-slate-800">{{ $depositStats['total'] ?? 0 }}</dd>
            </div>
            <div class="flex items-center justify-between">
                <dt class="text-sm text-slate-500">Approved</dt>
                <dd class="text-sm font-bold text-emerald-600">{{ $depositStats['approved'] ?? 0 }}</dd>
            </div>
            <div class="flex items-center justify-between">
                <dt class="text-sm text-slate-500">Rejected / Pending</dt>
                <dd class="text-sm font-bold text-slate-800">{{ ($depositStats['rejected'] ?? 0) + ($depositStats['pending'] ?? 0) }}</dd>
            </div>
        </dl>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6">
        <h3 class="text-sm font-semibold text-slate-700 mb-4">Refunds (30d)</h3>
        <dl class="space-y-3">
            <div class="flex items-center justify-between">
                <dt class="text-sm text-slate-500">Total</dt>
                <dd class="text-sm font-bold text-slate-800">{{ $refundStats['total'] ?? 0 }}</dd>
            </div>
            <div class="flex items-center justify-between">
                <dt class="text-sm text-slate-500">Approved</dt>
                <dd class="text-sm font-bold text-emerald-600">{{ $refundStats['approved'] ?? 0 }}</dd>
            </div>
            <div class="flex items-center justify-between">
                <dt class="text-sm text-slate-500">Rejected / Pending</dt>
                <dd class="text-sm font-bold text-slate-800">{{ ($refundStats['rejected'] ?? 0) + ($refundStats['pending'] ?? 0) }}</dd>
            </div>
        </dl>
    </div>
</div>

{{-- API activity --}}
<div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
        <div>
            <h3 class="text-sm font-semibold text-slate-700">API Activity (30d)</h3>
            <p class="text-xs text-slate-400 mt-0.5">External API call activity</p>
        </div>
        <span class="text-xs font-medium bg-slate-100 text-slate-600 px-3 py-1.5 rounded-full">API</span>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-left">
                <tr>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Type</th>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Count</th>
                    <th class="px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Total</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($apiActivity as $row)
                    <tr class="hover:bg-slate-50/50 transition">
                        <td class="px-6 py-4 font-medium text-slate-700">{{ $row['type'] }}</td>
                        <td class="px-6 py-4">
                            <span class="inline-flex px-2.5 py-1 rounded-full text-xs font-medium bg-indigo-50 text-indigo-700 border border-indigo-200">{{ $row['count'] }}</span>
                        </td>
                        <td class="px-6 py-4 font-bold text-slate-900">{{ number_format($row['total'], 2) }} <span class="text-xs font-medium text-slate-400">SAR</span></td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="px-6 py-12 text-center">
                            <p class="text-sm text-slate-400">No API activity recorded.</p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
