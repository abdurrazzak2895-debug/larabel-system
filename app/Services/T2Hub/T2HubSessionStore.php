<?php

namespace App\Services\T2Hub;

use Illuminate\Support\Facades\Crypt;

/**
 * Persists the T2Hub agent session (cookie header, AES key, CSRF value)
 * between requests.
 *
 * The snapshot is written with Laravel's encrypter, so the portal cookie and
 * the response-decryption key never sit on disk in plain text.
 */
class T2HubSessionStore
{
    public function path(): string
    {
        return (string) config('t2hub.session_store', storage_path('app/t2hub/session.json'));
    }

    /**
     * @return array{cookie: string, key: string, csrf: string, expires_at: int}|null
     */
    public function get(): ?array
    {
        $path = $this->path();

        if (! is_file($path)) {
            return null;
        }

        try {
            $payload = json_decode(Crypt::decryptString((string) file_get_contents($path)), true);
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($payload) || empty($payload['cookie'])) {
            return null;
        }

        $session = [
            'cookie' => (string) $payload['cookie'],
            'key' => (string) ($payload['key'] ?? ''),
            'csrf' => (string) ($payload['csrf'] ?? ''),
            'expires_at' => (int) ($payload['expires_at'] ?? 0),
        ];

        return $session;
    }

    /**
     * Return the stored session only while it is still inside its validity window.
     *
     * @return array{cookie: string, key: string, csrf: string, expires_at: int}|null
     */
    public function getFresh(): ?array
    {
        $session = $this->get();

        if ($session === null || $session['expires_at'] <= time()) {
            return null;
        }

        return $session;
    }

    /**
     * @param array{cookie: string, key: string, csrf?: string, expires_at?: int} $session
     */
    public function put(array $session): void
    {
        $path = $this->path();
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $payload = [
            'cookie' => (string) ($session['cookie'] ?? ''),
            'key' => (string) ($session['key'] ?? ''),
            'csrf' => (string) ($session['csrf'] ?? ''),
            'expires_at' => (int) ($session['expires_at'] ?? (time() + ((int) config('t2hub.session_ttl_minutes', 30) * 60))),
            'captured_at' => time(),
        ];

        file_put_contents($path, Crypt::encryptString((string) json_encode($payload)), LOCK_EX);
        @chmod($path, 0660);
    }

    public function forget(): void
    {
        if (is_file($this->path())) {
            @unlink($this->path());
        }
    }

    /**
     * Non-secret view of the stored session, safe to show in an admin panel.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $session = $this->get();

        return [
            'stored' => $session !== null,
            'fresh' => $this->getFresh() !== null,
            'has_key' => (bool) ($session['key'] ?? false),
            'cookie_length' => strlen((string) ($session['cookie'] ?? '')),
            'expires_at' => $session['expires_at'] ?? null,
            'expires_in_seconds' => $session ? max(0, $session['expires_at'] - time()) : null,
            'captured_at' => $session ? (int) (@json_decode(Crypt::decryptString((string) @file_get_contents($this->path())), true)['captured_at'] ?? 0) : null,
        ];
    }
}
