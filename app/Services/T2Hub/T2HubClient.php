<?php

namespace App\Services\T2Hub;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Low-level T2Hub agent-portal client.
 *
 * Responsibilities:
 *  - log in to the Livewire login form with the agent credentials,
 *  - keep the session cookie plus the page AES key (`window.__sk`),
 *  - call the read-only booking API and decrypt `x-encrypted: 1` responses.
 *
 * The decryption is AES-256-GCM where the raw key is the base64-decoded
 * `window.__sk` value and the payload is base64(iv[12] || ciphertext || tag[16]).
 */
class T2HubClient
{
    public function __construct(private readonly T2HubSessionStore $store)
    {
    }

    public function baseUrl(): string
    {
        return (string) config('t2hub.base_url');
    }

    public function appPath(): string
    {
        return (string) config('t2hub.app_path');
    }

    public function configured(): bool
    {
        return filled(config('t2hub.email')) && filled(config('t2hub.password'));
    }

    /**
     * Return a usable session, logging in when the stored one is missing or stale.
     *
     * @return array{cookie: string, key: string, csrf: string, expires_at: int}
     */
    public function session(bool $force = false): array
    {
        if (! $force) {
            $stored = $this->store->getFresh();

            if ($stored !== null) {
                return $stored;
            }
        }

        // A pre-captured cookie/key pair still works when the portal publishes
        // its AES key only to browser JavaScript (no server-side login possible).
        $fallbackCookie = (string) config('t2hub.session_cookie');
        $fallbackKey = (string) config('t2hub.session_key');

        if (! $this->configured()) {
            if (filled($fallbackCookie) && filled($fallbackKey)) {
                $session = [
                    'cookie' => $fallbackCookie,
                    'key' => $fallbackKey,
                    'csrf' => (string) config('t2hub.session_csrf'),
                    'expires_at' => time() + ((int) config('t2hub.session_ttl_minutes', 30) * 60),
                ];
                $this->store->put($session);

                return $session;
            }

            throw new RuntimeException('T2Hub credentials are not configured (T2HUB_EMAIL / T2HUB_PASSWORD or T2HUB_SESSION_COOKIE + T2HUB_SESSION_KEY).');
        }

        if (! config('t2hub.auto_login', true)) {
            throw new RuntimeException('T2Hub session is missing and automatic login is disabled.');
        }

        try {
            return $this->login();
        } catch (\Throwable $e) {
            // The portal throttles rapid repeat logins and occasionally serves a
            // page without the session key; one spaced retry clears both.
            $this->trace('login: first attempt failed, retrying', ['error' => $e->getMessage()]);
            sleep(3);

            return $this->login();
        }
    }

    /**
     * Authenticate against the portal and persist the captured session.
     *
     * @return array{cookie: string, key: string, csrf: string, expires_at: int}
     */
    public function login(): array
    {
        $loginUrl = (string) config('t2hub.login_url');
        $origin = rtrim((string) parse_url($loginUrl, PHP_URL_SCHEME) . '://' . parse_url($loginUrl, PHP_URL_HOST), '/');

        $this->trace('login: fetching the login page', ['url' => $loginUrl]);

        $page = $this->request()->get($loginUrl);
        $html = $page->body();

        $this->trace('login: login page loaded', ['status' => $page->status(), 'bytes' => strlen($html)]);

        if (! $page->successful()) {
            throw new RuntimeException("T2Hub login page returned HTTP {$page->status()}.");
        }

        $csrf = $this->match('/<meta[^>]+name=["\']csrf-token["\'][^>]+content=["\']([^"\']+)/i', $html)
            ?? throw new RuntimeException('T2Hub CSRF token was not found on the login page.');

        $snapshotRaw = $this->match('/wire:snapshot=["\']([\s\S]*?)["\']/i', $html)
            ?? throw new RuntimeException('T2Hub Livewire component was not found on the login page.');

        $snapshot = html_entity_decode($snapshotRaw, ENT_QUOTES | ENT_HTML5);
        json_decode($snapshot, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('T2Hub Livewire snapshot could not be decoded.');
        }

        $cookies = $this->cookieJar($page->headers()['Set-Cookie'] ?? []);

        $this->trace('login: form contract', [
            'csrf' => $csrf !== '',
            'snapshot_bytes' => strlen($snapshot),
            'cookie_bytes' => strlen($cookies),
        ]);

        $response = $this->request()
            ->withHeaders([
                'x-livewire' => 'true',
                'x-csrf-token' => $csrf,
                'referer' => $loginUrl,
                'cookie' => $cookies,
            ])
            ->post("{$origin}/livewire/update", [
                'components' => [[
                    'snapshot' => $snapshot,
                    'updates' => [
                        'data.mobile' => (string) config('t2hub.email'),
                        'data.password' => (string) config('t2hub.password'),
                        'data.remember' => true,
                    ],
                    'calls' => [[
                        'path' => '',
                        'method' => 'authenticate',
                        'params' => [],
                    ]],
                ]],
            ]);

        $body = $response->body();
        $cookies = $this->mergeCookies($cookies, $this->cookieJar($response->headers()['Set-Cookie'] ?? []));

        $this->trace('login: credentials submitted', [
            'status' => $response->status(),
            'location' => $response->header('Location') ?: null,
            'cookie_bytes' => strlen($cookies),
        ]);

        if (! $response->successful() && ! in_array($response->status(), [302, 303], true)) {
            throw new RuntimeException("T2Hub login failed with HTTP {$response->status()}.");
        }

        $decoded = json_decode($body, true);
        $location = $response->header('Location')
            ?: ($decoded['effects']['redirect'] ?? $decoded['effects']['url'] ?? null);
        $landingUrl = $location ? $this->absoluteUrl((string) $location, $loginUrl) : null;

        $key = null;

        if ($landingUrl !== null) {
            $key = $this->extractSessionKey($this->request()->withHeaders(['cookie' => $cookies])->get($landingUrl)->body());
        }

        foreach ([$this->baseUrl() . $this->appPath(), $this->baseUrl() . $this->appPath() . '/agent/login'] as $candidate) {
            if ($key !== null || $candidate === $landingUrl) {
                continue;
            }

            $probe = $this->request()->withHeaders(['cookie' => $cookies])->get($candidate);
            $key = $this->extractSessionKey($probe->body());

            $this->trace('login: session key probe', [
                'url' => $candidate,
                'status' => $probe->status(),
                'bytes' => strlen($probe->body()),
                'key_found' => $key !== null,
            ]);
        }

        $key ??= (string) config('t2hub.session_key');

        if (blank($key)) {
            throw new RuntimeException('T2Hub session key (window.__sk) could not be captured. Set T2HUB_SESSION_KEY with a captured key.');
        }

        $session = [
            'cookie' => $cookies,
            'key' => $key,
            'csrf' => $this->cookieValue($cookies, 'XSRF-TOKEN') ?? '',
            'expires_at' => time() + ((int) config('t2hub.session_ttl_minutes', 30) * 60),
        ];

        $this->store->put($session);

        Log::info('T2Hub session established', ['cookie_length' => strlen($cookies), 'has_key' => true]);

        return $session;
    }

    /**
     * GET a booking API path, e.g. get('/pacc/occupations', ['per_page' => 10000]).
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function get(string $path, array $params = [], bool $retry = true): array
    {
        return $this->send('GET', $path, $params, null, $retry);
    }

    /**
     * POST a booking API path.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function post(string $path, array $body = [], bool $retry = true): array
    {
        return $this->send('POST', $path, [], $body, $retry);
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $params, ?array $body, bool $retry): array
    {
        $session = $this->session();
        $url = $this->baseUrl() . $this->appPath() . '/api' . $path;

        if ($params !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query(array_filter(
                $params,
                static fn ($value) => $value !== null && $value !== '',
            ));
        }

        $request = $this->request()->withHeaders([
            'cookie' => $session['cookie'],
            'x-session-key' => $session['key'],
            'referer' => $this->baseUrl() . $this->appPath(),
        ]);

        $response = $method === 'POST'
            ? $request->post($url, $body ?? [])
            : $request->get($url);

        $status = $response->status();

        // A rotated or expired session answers 401/419/403. Log in once more.
        if (in_array($status, [401, 403, 419], true) && $retry && $this->configured()) {
            Log::info('T2Hub session rejected, re-authenticating', ['status' => $status, 'path' => $path]);
            $this->store->forget();

            return $this->send($method, $path, $params, $body, false);
        }

        if (! $response->successful()) {
            throw new RuntimeException("T2Hub request {$path} failed with HTTP {$status}.");
        }

        return $this->decode($response->body(), $response->header('x-encrypted'), $session['key'], $path);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $body, ?string $encrypted, string $keyRaw, string $path): array
    {
        if (trim((string) $encrypted) === '1') {
            // Encrypted responses arrive as {"p":"<base64(ciphertext|tag)>","iv":"<base64(iv)>"}.
            $envelope = json_decode($body, true);

            if (! is_array($envelope)) {
                $envelope = json_decode(trim($body, '"'), true);
            }

            if (! is_array($envelope) || ! isset($envelope['p'])) {
                throw new RuntimeException("T2Hub encrypted response for {$path} had no payload envelope.");
            }

            $body = $this->decryptEnvelope($envelope, $keyRaw, $path);
        }

        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            throw new RuntimeException("T2Hub response for {$path} was not valid JSON.");
        }

        return $decoded;
    }

    /**
     * AES-256-GCM decrypt of the portal's `{p, iv}` envelope.
     *
     * `p` is base64(ciphertext ‖ tag) and `iv` is base64(12-byte nonce); the
     * key is the base64-decoded `window.__sk` value.
     *
     * @param  array<string, mixed>  $envelope
     */
    public function decryptEnvelope(array $envelope, string $keyRaw, string $path = ''): string
    {
        $key = base64_decode($keyRaw, true);

        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('T2Hub session key is not a 32-byte base64 value.');
        }

        $cipher = base64_decode((string) $envelope['p'], true);

        if ($cipher === false || strlen($cipher) <= 16) {
            throw new RuntimeException('T2Hub encrypted payload is malformed.');
        }

        $ivValue = (string) ($envelope['iv'] ?? '');

        if ($ivValue !== '') {
            $iv = base64_decode($ivValue, true);

            if ($iv === false || strlen($iv) !== 12) {
                throw new RuntimeException('T2Hub encryption nonce is malformed.');
            }
        } else {
            // Older payloads prefix the nonce to the ciphertext instead.
            $iv = substr($cipher, 0, 12);
            $cipher = substr($cipher, 12);
        }

        $tag = substr($cipher, -16);
        $cipherText = substr($cipher, 0, -16);

        $plain = openssl_decrypt($cipherText, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

        if ($plain === false) {
            throw new RuntimeException("T2Hub response for {$path} could not be decrypted (stale session key?).");
        }

        return $plain;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function trace(string $message, array $context = []): void
    {
        if (! config('t2hub.debug', false)) {
            return;
        }

        Log::info($message, $context);
        fwrite(STDERR, '  [t2hub] ' . $message . ' ' . json_encode($context) . PHP_EOL);
    }

    private function request(): PendingRequest
    {
        return Http::withOptions([
            'allow_redirects' => false,
            'http_errors' => false,
        ])
            ->withHeaders([
                'accept' => 'application/json, text/html;q=0.9',
                'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36',
            ])
            ->timeout((float) config('t2hub.timeout', 25))
            ->connectTimeout((float) config('t2hub.connect_timeout', 10));
    }

    /**
     * @param  mixed  $header
     */
    private function cookieJar(mixed $header): string
    {
        $values = is_array($header) ? $header : ($header ? [$header] : []);
        $pairs = [];

        foreach ($values as $value) {
            foreach (preg_split('/,(?=[^;,=]+=[^;,]+)/', (string) $value) as $cookie) {
                $pair = trim(explode(';', $cookie)[0]);

                if (str_contains($pair, '=')) {
                    $pairs[] = $pair;
                }
            }
        }

        return implode('; ', $pairs);
    }

    private function mergeCookies(string ...$jars): string
    {
        $merged = [];

        foreach ($jars as $jar) {
            foreach (explode('; ', $jar) as $pair) {
                if (! str_contains($pair, '=')) {
                    continue;
                }

                [$name, $value] = explode('=', $pair, 2);
                $merged[trim($name)] = $value;
            }
        }

        return implode('; ', array_map(
            static fn ($name, $value) => "{$name}={$value}",
            array_keys($merged),
            array_values($merged),
        ));
    }

    private function cookieValue(string $jar, string $name): ?string
    {
        foreach (explode('; ', $jar) as $pair) {
            if (str_starts_with($pair, $name . '=')) {
                return substr($pair, strlen($name) + 1);
            }
        }

        return null;
    }

    private function extractSessionKey(string $html): ?string
    {
        return $this->match('/window\.__sk\s*=\s*["\']([^"\']+)["\']/i', $html)
            ?? $this->match('/__sk\s*:\s*["\']([^"\']+)["\']/i', $html);
    }

    private function match(string $pattern, string $subject): ?string
    {
        return preg_match($pattern, $subject, $matches) === 1 ? $matches[1] : null;
    }

    private function absoluteUrl(string $url, string $base): string
    {
        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        return rtrim($this->origin($base), '/') . '/' . ltrim($url, '/');
    }

    private function origin(string $url): string
    {
        $parts = parse_url($url);

        return ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
    }
}
