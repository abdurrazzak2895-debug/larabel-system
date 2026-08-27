<?php

namespace Tests\Unit;

use App\Services\SvpPaymentHistoryService;
use Tests\TestCase;

class SvpPaymentHistoryServiceTest extends TestCase
{
    public function test_payment_history_normalizes_shapes_and_filters_by_status_and_search(): void
    {
        $service = app(SvpPaymentHistoryService::class);
        $payload = [
            'data' => [
                'payments' => [
                    [
                        'id' => 101,
                        'merchant_transaction_id' => 'MERCHANT-PAID-101',
                        'payable_type' => 'Reservation',
                        'payable_id' => 5370112,
                        'amount' => '50.00',
                        'currency' => 'SAR',
                        'payment_method' => 'card',
                        'status' => 'completed',
                    ],
                    [
                        'id' => 102,
                        'merchant_transaction_id' => 'MERCHANT-PENDING-102',
                        'amount' => '50.00',
                        'status' => 'pending',
                    ],
                    [
                        'id' => 103,
                        'merchant_transaction_id' => 'MERCHANT-FAILED-103',
                        'result' => ['code' => '800.100.100'],
                    ],
                ],
            ],
        ];

        $all = $service->normalize($payload);
        $this->assertCount(3, $all);
        $this->assertSame('paid', $all[0]['status']);
        $this->assertSame('pending', $all[1]['status']);
        $this->assertSame('failed', $all[2]['status']);

        $paid = $service->normalize($payload, 'paid');
        $this->assertCount(1, $paid);
        $this->assertSame('MERCHANT-PAID-101', $paid[0]['reference']);

        $failed = $service->normalize($payload, 'failed', '103');
        $this->assertCount(1, $failed);
        $this->assertSame(103, $failed[0]['id']);
    }

    public function test_checkout_creation_code_is_pending_not_paid(): void
    {
        $service = app(SvpPaymentHistoryService::class);
        $payments = $service->normalize([
            'payments' => [
                ['id' => 201, 'result' => ['code' => '000.200.100']],
                ['id' => 202, 'result' => ['code' => '000.100.110']],
                ['id' => 203, 'status' => '000.100.112'],
            ],
        ], 'paid');

        $this->assertCount(2, $payments);
        $this->assertSame(202, $payments[0]['id']);
        $this->assertSame(203, $payments[1]['id']);
    }
}
