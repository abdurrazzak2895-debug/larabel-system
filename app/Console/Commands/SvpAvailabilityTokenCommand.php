<?php

namespace App\Console\Commands;

use App\Models\SvpAvailabilityAccount;
use App\Services\SvpAvailabilityTokenResolver;
use Illuminate\Console\Command;

class SvpAvailabilityTokenCommand extends Command
{
    protected $signature = 'svp:availability-token
        {account : Backend SVP availability account ID}
        {--expires= : Token expiry in ISO-8601 or Y-m-d H:i:s format}
        {--revoke : Revoke the account token instead of storing one}';

    protected $description = 'Seed, rotate, inspect, or revoke a backend-managed SVP availability token';

    public function handle(SvpAvailabilityTokenResolver $tokens): int
    {
        $account = SvpAvailabilityAccount::query()->find($this->argument('account'));

        if (! $account) {
            $this->error('SVP availability account was not found.');
            return self::FAILURE;
        }

        if ($this->option('revoke')) {
            $tokens->invalidate($account->getKey(), 'Token revoked by admin command.');
            $account->forceFill(['active' => false])->save();
            $this->info("Account {$account->getKey()} revoked.");
            return self::SUCCESS;
        }

        $token = trim((string) $this->secret('Paste the SVP bearer token (input is hidden)'));
        if ($token === '') {
            $this->error('A non-empty token is required.');
            return self::FAILURE;
        }

        $expiresAt = $this->option('expires');
        if ($expiresAt !== null && strtotime($expiresAt) === false) {
            $this->error('The --expires value is not a valid date.');
            return self::FAILURE;
        }

        $tokens->rememberToken($account, $token, null, $expiresAt);
        $this->info("Token encrypted and cached for account {$account->getKey()}.");

        return self::SUCCESS;
    }
}
