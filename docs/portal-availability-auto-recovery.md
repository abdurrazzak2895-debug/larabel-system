# Portal Availability Auto-Recovery for Laravel

This implementation adds a **read-only health monitor** around the existing `PortalAvailabilityProviderInterface`. It probes each configured account, retries transient failures with exponential backoff, refreshes the encrypted portal session only after a failed probe, opens a temporary circuit after repeated failures, and automatically returns the account to service after a successful probe. It does not call booking, hold, reservation, OTP, card, HyperPay, or payment endpoints.

The implementation deliberately does not mark a credential permanently inactive after an upstream failure. A temporary circuit is safer: it protects the provider from a retry storm while allowing the scheduler to recover the account without requiring an admin to click Activate.

## 1. Add recovery state to the credentials table

Create a migration with `php artisan make:migration add_recovery_state_to_portal_availability_credentials_table` and use the following code:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portal_availability_credentials', function (Blueprint $table): void {
            $table->unsignedInteger('recovery_failures')->default(0)->after('last_error');
            $table->timestamp('circuit_open_until')->nullable()->after('recovery_failures');
            $table->timestamp('last_recovered_at')->nullable()->after('circuit_open_until');
            $table->index(['active', 'circuit_open_until'], 'portal_credentials_recovery_idx');
        });
    }

    public function down(): void
    {
        Schema::table('portal_availability_credentials', function (Blueprint $table): void {
            $table->dropIndex('portal_credentials_recovery_idx');
            $table->dropColumn([
                'recovery_failures',
                'circuit_open_until',
                'last_recovered_at',
            ]);
        });
    }
};
```

Run the migration only after reviewing it in staging:

```bash
php artisan migrate
```

## 2. Extend the credential model

Add the following fields to `PortalAvailabilityCredential`.

```php
protected $fillable = [
    'name',
    'portal_account_id',
    'session_cookie',
    'expires_at',
    'last_used_at',
    'last_checked_at',
    'last_error',
    'recovery_failures',
    'circuit_open_until',
    'last_recovered_at',
    'active',
];

protected $casts = [
    'session_cookie' => 'encrypted',
    'expires_at' => 'datetime',
    'last_used_at' => 'datetime',
    'last_checked_at' => 'datetime',
    'circuit_open_until' => 'datetime',
    'last_recovered_at' => 'datetime',
    'active' => 'boolean',
    'recovery_failures' => 'integer',
];

public function scopeUsable(Builder $query): Builder
{
    return $query
        ->where('active', true)
        ->where(function (Builder $query): void {
            $query->whereNull('expires_at')
                ->orWhere('expires_at', '>', now()->addSeconds(30));
        })
        ->where(function (Builder $query): void {
            $query->whereNull('circuit_open_until')
                ->orWhere('circuit_open_until', '<=', now());
        });
}

public function hasUsableSession(int $skewSeconds = 30): bool
{
    return $this->active
        && filled($this->session_cookie)
        && ($this->expires_at === null
            || $this->expires_at->isAfter(now()->addSeconds($skewSeconds)))
        && ($this->circuit_open_until === null || $this->circuit_open_until->isPast());
}

public function circuitIsOpen(): bool
{
    return $this->circuit_open_until !== null
        && $this->circuit_open_until->isFuture();
}

public function markRecoverySuccess(): void
{
    $this->forceFill([
        'recovery_failures' => 0,
        'circuit_open_until' => null,
        'last_recovered_at' => now(),
        'last_checked_at' => now(),
        'last_error' => null,
    ])->saveQuietly();
}

public function markRecoveryFailure(string $message): void
{
    $failures = ((int) $this->recovery_failures) + 1;
    $threshold = max(1, (int) config('portal.recovery_failure_threshold', 3));
    $circuitMinutes = max(1, (int) config('portal.recovery_circuit_minutes', 5));

    $this->forceFill([
        'recovery_failures' => $failures,
        'circuit_open_until' => $failures >= $threshold
            ? now()->addMinutes($circuitMinutes)
            : null,
        'last_checked_at' => now(),
        'last_error' => \Illuminate\Support\Str::limit($message, 500),
    ])->saveQuietly();
}
```

If the existing model already contains `scopeUsable()` or `hasUsableSession()`, replace those methods rather than defining duplicate methods.

## 3. Add recovery configuration

Append these keys to `config/portal.php`:

```php
'recovery_probe_timeout' => (int) env('PORTAL_AVAILABILITY_RECOVERY_PROBE_TIMEOUT', 15),
'recovery_failure_threshold' => (int) env('PORTAL_AVAILABILITY_RECOVERY_FAILURE_THRESHOLD', 3),
'recovery_circuit_minutes' => (int) env('PORTAL_AVAILABILITY_RECOVERY_CIRCUIT_MINUTES', 5),
'recovery_retry_attempts' => (int) env('PORTAL_AVAILABILITY_RECOVERY_RETRY_ATTEMPTS', 2),
'recovery_retry_delay_ms' => (int) env('PORTAL_AVAILABILITY_RECOVERY_RETRY_DELAY_MS', 500),
'recovery_retry_max_delay_ms' => (int) env('PORTAL_AVAILABILITY_RECOVERY_RETRY_MAX_DELAY_MS', 5000),
'recovery_refresh_cooldown_minutes' => (int) env('PORTAL_AVAILABILITY_RECOVERY_REFRESH_COOLDOWN_MINUTES', 10),
```

Recommended initial production values are:

```env
PORTAL_AVAILABILITY_RECOVERY_PROBE_TIMEOUT=15
PORTAL_AVAILABILITY_RECOVERY_FAILURE_THRESHOLD=3
PORTAL_AVAILABILITY_RECOVERY_CIRCUIT_MINUTES=5
PORTAL_AVAILABILITY_RECOVERY_RETRY_ATTEMPTS=2
PORTAL_AVAILABILITY_RECOVERY_RETRY_DELAY_MS=500
PORTAL_AVAILABILITY_RECOVERY_RETRY_MAX_DELAY_MS=5000
PORTAL_AVAILABILITY_RECOVERY_REFRESH_COOLDOWN_MINUTES=10
```

## 4. Add the auto-recovery methods to `PortalAvailabilityService`

Add these methods inside the existing `PortalAvailabilityService` class. They use only the existing read-only occupations endpoint for probing and the existing authorized account refresh endpoint for cookie recovery.

```php
/**
 * Probe and recover every active credential without touching booking/payment APIs.
 *
 * @return array{checked:int, healthy:int, recovered:int, circuit_open:int, failed:int, failures:array<int,array{id:int,name:string,message:string}>}
 */
public function autoRecoverCredentials(): array
{
    $summary = [
        'checked' => 0,
        'healthy' => 0,
        'recovered' => 0,
        'circuit_open' => 0,
        'failed' => 0,
        'failures' => [],
    ];

    $credentials = PortalAvailabilityCredential::query()
        ->where('active', true)
        ->whereNotNull('session_cookie')
        ->orderBy('id')
        ->get();

    foreach ($credentials as $credential) {
        $summary['checked']++;

        if ($credential->circuitIsOpen()) {
            $summary['circuit_open']++;
            continue;
        }

        try {
            $this->probeWithRetry($credential);
            $credential->markRecoverySuccess();
            $summary['healthy']++;
            continue;
        } catch (\Throwable $firstFailure) {
            Log::warning('Portal availability health probe failed; attempting session refresh', [
                'credential_id' => $credential->id,
                'portal_account_id' => $credential->portal_account_id,
                'error' => $firstFailure->getMessage(),
            ]);
        }

        try {
            $this->refreshCredential($credential->fresh());
            $refreshed = $credential->fresh();
            $this->probeWithRetry($refreshed);
            $refreshed->markRecoverySuccess();
            $summary['recovered']++;
        } catch (\Throwable $exception) {
            $current = $credential->fresh();
            $current->markRecoveryFailure($exception->getMessage());
            $summary['failed']++;
            $summary['failures'][] = [
                'id' => (int) $current->id,
                'name' => (string) $current->name,
                'message' => \Illuminate\Support\Str::limit($exception->getMessage(), 200),
            ];

            Log::warning('Portal availability account recovery failed', [
                'credential_id' => $current->id,
                'portal_account_id' => $current->portal_account_id,
                'recovery_failures' => $current->recovery_failures,
                'circuit_open_until' => $current->circuit_open_until?->toIso8601String(),
                'error' => $exception->getMessage(),
            ]);
        }

        usleep(max(0, (int) config('portal.rate_limit_delay_ms', 250)) * 1000);
    }

    return $summary;
}

private function probeWithRetry(PortalAvailabilityCredential $credential): void
{
    $attempts = max(0, min(3, (int) config('portal.recovery_retry_attempts', 2)));
    $lastException = null;

    for ($attempt = 0; $attempt <= $attempts; $attempt++) {
        if ($attempt > 0) {
            $base = max(0, (int) config('portal.recovery_retry_delay_ms', 500));
            $maximum = max($base, (int) config('portal.recovery_retry_max_delay_ms', 5000));
            $delay = min($maximum, $base * (2 ** ($attempt - 1)));
            usleep($delay * 1000);
        }

        try {
            $occupations = $this->provider->occupations((string) $credential->session_cookie);
            if ($occupations === []) {
                throw new RuntimeException('Portal availability health probe returned no occupations.');
            }

            return;
        } catch (\Throwable $exception) {
            $lastException = $exception;
            if (! $this->isRecoveryRetryable($exception) || $attempt === $attempts) {
                throw $exception;
            }
        }
    }

    throw $lastException ?? new RuntimeException('Portal availability health probe failed.');
}

private function isRecoveryRetryable(\Throwable $exception): bool
{
    $message = Str::lower($exception->getMessage());

    if (Str::contains($message, [
        'expired',
        'not authorized',
        'unauthorized',
        'invalid cookie',
        'invalid session',
    ])) {
        return false;
    }

    return Str::contains($message, [
        'timeout',
        'timed out',
        'connection',
        'curl',
        'http 429',
        'http 5',
        'no occupations',
        'temporarily unavailable',
    ]);
}
```

The existing `refreshCredential()` method should remain the authoritative implementation for refresh-cookie rotation. Do not duplicate cookie parsing in the recovery monitor.

## 5. Add the scheduled Artisan command

Add this command to `routes/console.php`:

```php
Artisan::command('portal:recover-availability', function (): int {
    $summary = app(\App\Services\PortalAvailabilityService::class)->autoRecoverCredentials();

    $this->table([
        'Checked',
        'Healthy',
        'Recovered',
        'Circuit open',
        'Failed',
    ], [[
        $summary['checked'],
        $summary['healthy'],
        $summary['recovered'],
        $summary['circuit_open'],
        $summary['failed'],
    ]]);

    if ($summary['failures'] !== []) {
        $this->table(
            ['Credential ID', 'Name', 'Message'],
            collect($summary['failures'])
                ->map(fn (array $failure): array => [
                    $failure['id'],
                    $failure['name'],
                    $failure['message'],
                ])
                ->all(),
        );
    }

    return $summary['failed'] > 0 ? 1 : 0;
})->purpose('Probe and automatically recover read-only Portal Availability sessions');

Schedule::command('portal:recover-availability')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->description('Probe and recover Portal Availability sessions without touching booking or payment flows.');
```

Keep the existing manual `portal:refresh-availability` command for an administrator who explicitly wants to refresh sessions. The scheduled recovery command is intentionally separate and does not require a browser to remain open.

The deployment must run Laravel’s scheduler every minute, as already used by the existing Railway cron process:

```bash
php artisan schedule:run
```

## 6. Surface account health in the admin page

Add these fields to the credential summary returned by `PortalAvailabilityService::credentials()`:

```php
'recovery_failures' => (int) $credential->recovery_failures,
'circuit_open_until' => $credential->circuit_open_until?->toIso8601String(),
'last_recovered_at' => $credential->last_recovered_at?->toIso8601String(),
'circuit_open' => $credential->circuitIsOpen(),
```

In the Blade account card, show an amber message when `circuit_open` is true:

```blade
@if ($credential['circuit_open'])
    <p class="mt-2 text-xs text-amber-700">
        Temporary recovery pause until {{ $credential['circuit_open_until'] }}.
        The scheduler will retry automatically.
    </p>
@elseif (($credential['recovery_failures'] ?? 0) > 0)
    <p class="mt-2 text-xs text-amber-700">
        Recovery attempts: {{ $credential['recovery_failures'] }}.
        The account remains isolated while other accounts continue serving availability.
    </p>
@endif
```

For an empty centers response, retain the safe behavior of showing no stale slot and use wording such as:

```text
No live centers returned after bounded retries. The upstream portal may be temporarily unavailable, or this slot changed after the date search. Retry shortly; no stale availability is being shown.
```

## 7. Add focused tests

Add a test that verifies a transient probe failure is retried and then recovers:

```php
public function test_auto_recovery_retries_transient_probe_and_marks_account_healthy(): void
{
    config([
        'portal.recovery_retry_attempts' => 2,
        'portal.recovery_retry_delay_ms' => 0,
        'portal.recovery_retry_max_delay_ms' => 0,
    ]);

    $credential = PortalAvailabilityCredential::query()->create([
        'name' => 'Recoverable session',
        'portal_account_id' => 'portal-account-recoverable',
        'session_cookie' => 'session=recoverable',
        'active' => true,
    ]);

    $calls = 0;
    $provider = new class($calls) implements \App\Contracts\PortalAvailabilityProviderInterface
    {
        public function __construct(private int &$calls) {}

        public function refreshAccount(string $sessionCookie, string $accountId): array
        {
            return ['session_cookie' => null, 'expires_at' => null, 'rotated' => false];
        }

        public function occupations(string $sessionCookie): array
        {
            $this->calls++;
            if ($this->calls < 3) {
                throw new \RuntimeException('Portal availability request timed out.');
            }

            return [['name' => 'Load and Unload Worker', 'occupation_id' => 2061, 'category_id' => 159]];
        }

        public function searchDates(string $sessionCookie, string $accountId, int|string $categoryId, string $startFrom): array
        {
            return ['dates' => []];
        }

        public function centers(string $sessionCookie, string $accountId, int|string $categoryId, string $city, string $date, int|string $occupationId, string $languageCode): array
        {
            return ['centers' => []];
        }
    };

    $summary = (new \App\Services\PortalAvailabilityService($provider))->autoRecoverCredentials();

    $this->assertSame(1, $summary['healthy']);
    $this->assertSame(0, $summary['failed']);
    $this->assertSame(3, $calls);
    $this->assertSame(0, $credential->fresh()->recovery_failures);
    $this->assertNull($credential->fresh()->circuit_open_until);
}
```

Add a second test for the circuit breaker:

```php
public function test_repeated_recovery_failures_open_a_temporary_circuit(): void
{
    config([
        'portal.recovery_retry_attempts' => 0,
        'portal.recovery_failure_threshold' => 1,
        'portal.recovery_circuit_minutes' => 5,
    ]);

    $credential = PortalAvailabilityCredential::query()->create([
        'name' => 'Unavailable session',
        'portal_account_id' => 'portal-account-unavailable',
        'session_cookie' => 'session=unavailable',
        'active' => true,
    ]);

    $provider = new class implements \App\Contracts\PortalAvailabilityProviderInterface
    {
        public function refreshAccount(string $sessionCookie, string $accountId): array
        {
            throw new \RuntimeException('Portal session expired or not authorized.');
        }

        public function occupations(string $sessionCookie): array
        {
            throw new \RuntimeException('Portal session expired or not authorized.');
        }

        public function searchDates(string $sessionCookie, string $accountId, int|string $categoryId, string $startFrom): array
        {
            return ['dates' => []];
        }

        public function centers(string $sessionCookie, string $accountId, int|string $categoryId, string $city, string $date, int|string $occupationId, string $languageCode): array
        {
            return ['centers' => []];
        }
    };

    $summary = (new \App\Services\PortalAvailabilityService($provider))->autoRecoverCredentials();
    $fresh = $credential->fresh();

    $this->assertSame(1, $summary['failed']);
    $this->assertSame(1, $fresh->recovery_failures);
    $this->assertTrue($fresh->circuitIsOpen());
}
```

Also assert that the booking lookup continues to aggregate other usable credentials when one credential has an open circuit. Existing multi-account aggregation tests should remain in place.

## 8. Operational safety rules

The monitor must never retry indefinitely. Keep the attempt count capped, keep the delay capped, and use `withoutOverlapping()` on the scheduled command. Do not store cookies, passwords, OTPs, or access tokens in logs or browser JavaScript.

A truly expired or unauthorized session cannot be regenerated by application code unless the provider’s authorized refresh endpoint accepts the existing session. In that case the monitor records the failure, opens a temporary circuit, and continues using other healthy accounts. An administrator must rotate the encrypted cookie when the provider requires a new login, OTP, or CAPTCHA.

After installation, verify the command manually in staging:

```bash
php artisan portal:recover-availability -v
php artisan schedule:list
php artisan test tests/Feature/PortalAvailabilityServiceTest.php
php artisan test
```

This auto-recovery layer is intentionally independent of `config/svp.php`, `BookingService`, SVP card checkout, HyperPay callbacks, temporary booking holds, and booking confirmation. Those flows should not be modified as part of this change.
