<?php

namespace App\Console\Commands;

use App\Services\SvpApiService;
use App\Services\SvpOtp\OtpAutoVerifier;
use Illuminate\Console\Command;

/**
 * Verify an SVP login for a portal identity without writing a browser session.
 *
 * Browser session persistence belongs to SvpLoginController + SvpAutoSession,
 * because a CLI process does not have the user's encrypted session cookie.
 */
class SvpScopedLoginCommand extends Command
{
    protected $signature = 'svp:scoped-login
        {--email-env=SVP_LOGIN_EMAIL : Environment variable containing the SVP email}
        {--password-env=SVP_LOGIN_PASSWORD : Environment variable containing the SVP password}
        {--otp-env=SVP_LOGIN_OTP : Environment variable containing a one-time OTP}
        {--portal-user-id= : Current portal user ID used to display the intended scope}
        {--agency-id=none : Current portal agency ID used to display the intended scope}
        {--mailbox : Read and verify the OTP through the configured mailbox driver}
        {--otp-method=email : SVP OTP delivery method}';

    protected $description = 'Verify SVP login/OTP for a user/agency scope without persisting a bearer token';

    public function handle(SvpApiService $svp, OtpAutoVerifier $verifier): int
    {
        $email = trim((string) env((string) $this->option('email-env')));
        $password = (string) env((string) $this->option('password-env'));
        $otpMethod = (string) $this->option('otp-method');

        if ($email === '' || $password === '') {
            $this->error('Missing SVP credentials. Set the selected email/password environment variables.');
            return self::FAILURE;
        }

        $scope = sprintf(
            'svp:session:%s:%s:token',
            (string) $this->option('agency-id'),
            (string) ($this->option('portal-user-id') ?: 'unknown'),
        );
        $this->line('Intended scoped session key: '.$scope);
        $this->warn('This command never persists or prints a bearer token. Use the web OTP flow to bind it to the browser session.');

        try {
            if ($this->option('mailbox')) {
                $result = $verifier->login($email, $password, $otpMethod);
            } else {
                $login = $svp->login($email, $password, $otpMethod);
                $this->line('Login status: '.(int) ($login['status'] ?? 0));
                if ((int) ($login['status'] ?? 0) >= 400) {
                    $this->error('SVP login rejected: '.$this->safeMessage((array) ($login['body'] ?? [])));
                    return self::FAILURE;
                }

                $otp = trim((string) env((string) $this->option('otp-env')));
                if ($otp === '') {
                    $this->line('SVP requested 2FA. Set the OTP environment variable and rerun, or use --mailbox.');
                    return self::SUCCESS;
                }

                $result = $svp->verifyOtp($email, $password, $otp, $otpMethod);
            }
        } catch (\Throwable $e) {
            $this->error('SVP authentication request failed: '.$e->getMessage());
            return self::FAILURE;
        }

        $body = (array) ($result['body'] ?? []);
        $token = $this->findToken($body);
        $this->line('Verification status: '.(int) ($result['status'] ?? 0));
        $this->line('OTP auto-verified: '.(! empty($result['auto_verified']) ? 'yes' : 'no'));
        $this->line('Token returned: '.($token !== null ? 'yes' : 'no'));
        $this->line('Response keys: '.implode(', ', array_keys($body)));

        if (($result['status'] ?? 0) >= 400 || $token === null) {
            $this->error('SVP verification did not produce a token: '.$this->safeMessage($body));
            return self::FAILURE;
        }

        $this->info('SVP verification succeeded. No browser session was modified.');
        return self::SUCCESS;
    }

    private function findToken(array $data): ?string
    {
        foreach ($data as $key => $value) {
            if (in_array($key, ['token', 'access_token', 'access'], true) && is_string($value) && trim($value) !== '') {
                return $value;
            }
            if (is_array($value)) {
                $nested = $this->findToken($value);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }
        return null;
    }

    private function safeMessage(array $body): string
    {
        $message = data_get($body, 'message');
        if (is_string($message) && trim($message) !== '') {
            return trim($message);
        }
        $errors = data_get($body, 'errors');
        return is_array($errors) ? 'upstream validation error' : 'unknown upstream response';
    }
}
