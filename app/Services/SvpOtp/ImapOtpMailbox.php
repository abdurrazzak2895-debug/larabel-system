<?php

namespace App\Services\SvpOtp;

use Illuminate\Support\Facades\Log;

/**
 * Minimal IMAP reader that needs no php-imap extension.
 *
 * Only the handful of IMAP commands required to log in, select a folder, find
 * the newest messages and read their bodies are implemented, over implicit TLS.
 * The peer certificate can be pinned by SHA-256 fingerprint because some
 * mailbox hosts do not present a CA-verifiable chain.
 */
final class ImapOtpMailbox implements OtpMailbox
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        private readonly string $password,
        private readonly string $folder = 'INBOX',
        private readonly bool $verifyPeer = false,
        private readonly ?string $fingerprint = null,
        private readonly int $timeout = 20,
        private readonly int $scanMessages = 8,
    ) {}

    public function describe(): string
    {
        return sprintf('imap %s:%d (%s)', $this->host, $this->port, $this->username);
    }

    public function fetchLatestCode(?int $since = null): ?array
    {
        $socket = @stream_socket_client(
            sprintf('ssl://%s:%d', $this->host, $this->port),
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            stream_context_create(['ssl' => $this->sslOptions()]),
        );

        if (! is_resource($socket)) {
            Log::warning('SVP OTP: IMAP connect failed', ['errno' => $errno, 'error' => $errstr]);

            return null;
        }

        stream_set_timeout($socket, $this->timeout);

        try {
            $this->readLine($socket); // greeting

            if (! $this->command($socket, 'LOGIN ' . $this->quote($this->username) . ' ' . $this->quote($this->password), 'A1')) {
                return null;
            }

            if (! $this->command($socket, 'SELECT ' . $this->quote($this->folder), 'A2')) {
                return null;
            }

            $search = $since !== null
                ? 'SINCE ' . date('d-M-Y', $since)
                : 'ALL';

            $response = $this->command($socket, 'SEARCH ' . $search, 'A3', true);

            if ($response === null) {
                return null;
            }

            preg_match('/\* SEARCH ([0-9 ]*)/', $response, $matches);
            $ids = array_values(array_filter(explode(' ', trim($matches[1] ?? ''))));

            if ($ids === []) {
                return null;
            }

            foreach (array_reverse(array_slice($ids, -$this->scanMessages)) as $id) {
                $raw = $this->command($socket, 'FETCH ' . $id . ' BODY.PEEK[]', 'A4', true);

                if ($raw === null) {
                    continue;
                }

                $code = $this->extractCode($raw);

                if ($code !== null) {
                    return [
                        'code' => $code,
                        'subject' => $this->extractSubject($raw),
                        'received_at' => time(),
                        'source' => 'imap',
                    ];
                }
            }

            return null;
        } finally {
            $this->command($socket, 'LOGOUT', 'A9');
            fclose($socket);
        }
    }

    private function sslOptions(): array
    {
        $options = [
            'verify_peer' => $this->verifyPeer,
            'verify_peer_name' => $this->verifyPeer,
            'allow_self_signed' => ! $this->verifyPeer,
        ];

        if ($this->fingerprint !== null && $this->fingerprint !== '') {
            $options['verify_peer'] = true;
            $options['verify_peer_name'] = false;
            $options['allow_self_signed'] = true;
            $options['peer_fingerprint'] = ['sha256' => strtolower($this->fingerprint)];
        }

        return $options;
    }

    private function quote(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    private function readLine($socket): string
    {
        $line = '';

        while (($chunk = fgets($socket, 8192)) !== false) {
            $line .= $chunk;

            if (str_ends_with($line, "\n")) {
                break;
            }
        }

        return $line;
    }

    /**
     * Send one command and read until its tag comes back.
     *
     * @return string|null null when the server answered with NO/BAD
     */
    private function command($socket, string $command, string $tag, bool $keepBody = false): ?string
    {
        fwrite($socket, $tag . ' ' . $command . "\r\n");
        $collected = '';

        while (true) {
            $line = $this->readLine($socket);

            if ($line === '') {
                return null;
            }

            if (str_starts_with($line, $tag . ' ')) {
                $status = strtoupper(substr(trim($line), strlen($tag) + 1, 3));

                if ($status !== 'OK') {
                    Log::warning('SVP OTP: IMAP command failed', ['tag' => $tag, 'reply' => trim($line)]);

                    return null;
                }

                return $keepBody ? $collected : '';
            }

            $collected .= $line;
        }
    }

    private function extractSubject(string $raw): string
    {
        if (preg_match('/^Subject:\s*(.+)$/mi', $raw, $matches) !== 1) {
            return '';
        }

        return trim(mb_decode_mimeheader(trim($matches[1])));
    }

    /**
     * Pull the OTP out of the raw message: prefer a code next to a keyword,
     * otherwise fall back to the first 4-8 digit group.
     */
    private function extractCode(string $raw): ?string
    {
        $body = quoted_printable_decode($raw);

        if (preg_match('/(?:otp|one[- ]?time|verification|code|pin)[^0-9]{0,40}(\d{4,8})/i', $body, $m) === 1) {
            return $m[1];
        }

        return preg_match('/\b(\d{6})\b/', $body, $m) === 1 ? $m[1] : null;
    }
}
