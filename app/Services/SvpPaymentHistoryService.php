<?php

namespace App\Services;

/**
 * Normalizes the authenticated SVP payment-list response for User and Agency
 * dashboards. This service only reads and filters the response; it never
 * creates, updates, refunds, or validates a payment.
 */
class SvpPaymentHistoryService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function normalize(array $payload, ?string $status = null, ?string $search = null): array
    {
        $payments = array_map(
            fn (mixed $payment): array => $this->normalizePayment((array) $payment),
            $this->extractRecords($payload)
        );

        $status = $this->normalizeFilter($status);
        $search = trim((string) $search);

        return array_values(array_filter($payments, function (array $payment) use ($status, $search): bool {
            if ($status !== null && $payment['status'] !== $status) {
                return false;
            }

            if ($search === '') {
                return true;
            }

            $haystack = strtolower(implode(' ', [
                (string) ($payment['id'] ?? ''),
                (string) ($payment['transaction_id'] ?? ''),
                (string) ($payment['reference'] ?? ''),
                (string) ($payment['payable_id'] ?? ''),
                (string) ($payment['payable_type'] ?? ''),
                (string) ($payment['method'] ?? ''),
                (string) ($payment['raw_status'] ?? ''),
            ]));

            return str_contains($haystack, strtolower($search));
        }));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractRecords(array $payload): array
    {
        if (array_is_list($payload)) {
            return $payload;
        }

        foreach ([
            'data.payments',
            'payments',
            'data.items',
            'items',
            'data',
        ] as $path) {
            $value = data_get($payload, $path);
            if (! is_array($value)) {
                continue;
            }

            if (array_is_list($value)) {
                return $value;
            }

            if ($this->looksLikePayment($value)) {
                return [$value];
            }
        }

        return $this->looksLikePayment($payload) ? [$payload] : [];
    }

    /**
     * @param array<string, mixed> $payment
     * @return array<string, mixed>
     */
    private function normalizePayment(array $payment): array
    {
        $code = $this->first($payment, [
            'result.code',
            'response.result.code',
            'payment.result.code',
            'payment_status.result.code',
            'status_code',
            'code',
        ]);
        $rawStatusValue = $this->first($payment, [
            'status',
            'payment_status',
            'state',
            'result.status',
            'response.status',
            'payment.status',
            'result.description',
        ]);
        $rawStatus = is_scalar($rawStatusValue) ? strtolower(trim((string) $rawStatusValue)) : '';
        if ($code === null && preg_match('/^\d{3}\./', $rawStatus) === 1) {
            $code = $rawStatus;
        }

        return [
            'id' => $this->first($payment, ['id', 'payment_id']),
            'transaction_id' => $this->first($payment, [
                'transaction_id',
                'transaction.id',
                'merchant_transaction_id',
                'ndc',
            ]),
            'reference' => $this->first($payment, [
                'merchant_transaction_id',
                'merchantTransactionId',
                'reference',
                'order_id',
                'orderId',
            ]),
            'payable_id' => $this->first($payment, ['payable_id', 'payable.id']),
            'payable_type' => $this->first($payment, ['payable_type', 'payable.type']),
            'amount' => $this->first($payment, ['amount', 'payment.amount', 'total']),
            'currency' => $this->first($payment, ['currency', 'currency_code', 'payment.currency']) ?: 'SAR',
            'method' => $this->first($payment, ['payment_method', 'method', 'payment.method']),
            'created_at' => $this->first($payment, ['created_at', 'createdAt', 'transaction_date', 'date']),
            'raw_status' => $rawStatus !== '' ? $rawStatus : null,
            'result_code' => is_scalar($code) ? (string) $code : null,
            'status' => $this->classifyStatus($rawStatus, is_scalar($code) ? (string) $code : null),
        ] + $payment;
    }

    private function classifyStatus(string $rawStatus, ?string $code): string
    {
        if (in_array($rawStatus, ['paid', 'success', 'successful', 'succeeded', 'completed', 'confirmed', 'approved'], true)) {
            return 'paid';
        }

        if (in_array($rawStatus, ['failed', 'failure', 'declined', 'rejected', 'error'], true)) {
            return 'failed';
        }

        if (in_array($rawStatus, ['cancelled', 'canceled', 'voided'], true)) {
            return 'cancelled';
        }

        if (in_array($rawStatus, ['refunded', 'refund'], true)) {
            return 'refunded';
        }

        if ($code !== null && str_starts_with($code, '000.') && ! str_starts_with($code, '000.200.')) {
            return 'paid';
        }

        if ($code !== null && ! str_starts_with($code, '000.')) {
            return 'failed';
        }

        return 'pending';
    }

    private function normalizeFilter(?string $status): ?string
    {
        $status = strtolower(trim((string) $status));

        return in_array($status, ['paid', 'pending', 'failed', 'cancelled', 'refunded'], true)
            ? $status
            : null;
    }

    /**
     * @param array<string, mixed> $value
     * @param array<int, string> $paths
     */
    private function first(array $value, array $paths): mixed
    {
        foreach ($paths as $path) {
            $candidate = data_get($value, $path);
            if ($candidate !== null && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $value
     */
    private function looksLikePayment(array $value): bool
    {
        return array_key_exists('id', $value)
            || array_key_exists('payment_id', $value)
            || array_key_exists('transaction_id', $value)
            || array_key_exists('merchant_transaction_id', $value)
            || array_key_exists('payment_method', $value)
            || array_key_exists('status', $value);
    }
}
