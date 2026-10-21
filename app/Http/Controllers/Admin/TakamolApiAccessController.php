<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TakamolApiConsumerKey;
use App\Services\T2Hub\T2HubClient;
use App\Services\T2Hub\T2HubSessionStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class TakamolApiAccessController extends Controller
{
    public function index(T2HubClient $client, T2HubSessionStore $store): View
    {
        return view('admin.takamol-api-access.index', [
            'apiKeys' => TakamolApiConsumerKey::query()->orderByDesc('created_at')->get(),
            'credentialsConfigured' => $client->configured(),
            'sessionSummary' => $store->summary(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'rate_limit_per_minute' => ['required', 'integer', 'min:1', 'max:1000'],
        ]);

        $plaintext = TakamolApiConsumerKey::generatePlaintext();
        TakamolApiConsumerKey::query()->create([
            'name' => trim($data['name']),
            'key_prefix' => TakamolApiConsumerKey::prefix($plaintext),
            'key_hash' => TakamolApiConsumerKey::hashPlaintext($plaintext),
            'expires_at' => $data['expires_at'] ?? null,
            'rate_limit_per_minute' => (int) $data['rate_limit_per_minute'],
        ]);

        return back()
            ->with('success', 'Takamol API key created. Copy it now; it will not be shown again.')
            ->with('created_takamol_api_key', $plaintext);
    }

    public function revoke(TakamolApiConsumerKey $apiKey): RedirectResponse
    {
        if ($apiKey->revoked_at === null) {
            $apiKey->forceFill(['revoked_at' => now()])->saveQuietly();
        }

        return back()->with('success', 'Takamol API key revoked.');
    }
}
