<?php

namespace App\Services\SvpOtp;

/**
 * Reads the newest SVP one-time password out of a mailbox.
 *
 * Implementations must return only codes that arrived at or after $since, so a
 * stale code from an earlier attempt can never be replayed.
 */
interface OtpMailbox
{
    /**
     * @return array{code: string, subject: string, received_at: ?int, source: string}|null
     */
    public function fetchLatestCode(?int $since = null): ?array;

    /**
     * Human readable name used in logs and the test command.
     */
    public function describe(): string;
}
