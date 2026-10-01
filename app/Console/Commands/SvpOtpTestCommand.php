<?php

namespace App\Console\Commands;

use App\Services\SvpOtp\OtpAutoVerifier;
use Illuminate\Console\Command;

class SvpOtpTestCommand extends Command
{
    protected $signature = 'svp:otp-test
        {--code-only : Only read the mailbox, do not touch SVP}
        {--email= : SVP account email (defaults to SVP_OTP_TEST_EMAIL)}
        {--timeout=90 : Seconds to wait for the code}';

    protected $description = 'Read the SVP email OTP from the configured mailbox (and optionally complete the SVP login)';

    public function handle(OtpAutoVerifier $verifier): int
    {
        $mailbox = $verifier->mailbox();

        if ($mailbox === null) {
            $this->error('No OTP mailbox configured. Set SVP_OTP_MAILBOX_DRIVER to dakbox or imap.');

            return self::FAILURE;
        }

        $this->line('Mailbox: ' . $mailbox->describe());
        $this->line('Auto verify: ' . ($verifier->enabled() ? 'enabled' : 'disabled (SVP_OTP_AUTO_VERIFY=false)'));

        $code = $verifier->awaitCode(time() - 900, (int) $this->option('timeout'));

        if ($code === null) {
            $this->error('No OTP found in the mailbox.');

            return self::FAILURE;
        }

        $this->info(sprintf(
            'OTP %s from %s (%s)',
            $code['code'],
            $code['source'],
            $code['subject'] !== '' ? $code['subject'] : 'no subject',
        ));

        if ($this->option('code-only')) {
            return self::SUCCESS;
        }

        $email = (string) ($this->option('email') ?: config('svp.otp_test_email'));

        if ($email === '') {
            $this->warn('No --email given; mailbox read only.');

            return self::SUCCESS;
        }

        $password = (string) config('svp.otp_test_password');

        if ($password === '') {
            $this->warn('SVP_OTP_TEST_PASSWORD is not set; mailbox read only.');

            return self::SUCCESS;
        }

        $result = $verifier->login($email, $password);

        $this->line('SVP status: ' . $result['status']);
        $this->line('Auto verified: ' . ($result['auto_verified'] ? 'yes' : 'no'));
        $this->line('Body: ' . mb_substr(json_encode($result['body']) ?: '', 0, 300));

        return $result['status'] === 200 ? self::SUCCESS : self::FAILURE;
    }
}
