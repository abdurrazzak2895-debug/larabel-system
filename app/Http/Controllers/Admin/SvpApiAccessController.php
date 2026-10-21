<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CandidateSvpSession;
use App\Models\SvpApiConsumerKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class SvpApiAccessController extends Controller
{
    public function index(): View
    {
        return view('admin.svp-api-access.index', [
            'sessions' => CandidateSvpSession::query()
                ->with(['candidate', 'user', 'agency'])
                ->orderByDesc('last_used_at')
                ->orderByDesc('id')
                ->get(),
            'apiKeys' => SvpApiConsumerKey::query()
                ->with(['session.candidate', 'session.user'])
                ->orderByDesc('created_at')
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'candidate_svp_session_id' => ['required', 'integer', 'exists:candidate_svp_sessions,id'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'rate_limit_per_minute' => ['required', 'integer', 'min:1', 'max:1000'],
        ]);

        $session = CandidateSvpSession::query()
            ->with('candidate')
            ->findOrFail($data['candidate_svp_session_id']);
        if (! $session->usable()) {
            return back()->withErrors([
                'candidate_svp_session_id' => 'Choose a saved SVP session with a non-expired bearer token.',
            ])->withInput();
        }

        $plaintext = SvpApiConsumerKey::generatePlaintext();
        SvpApiConsumerKey::query()->create([
            'candidate_svp_session_id' => $session->id,
            'name' => trim($data['name']),
            'key_prefix' => SvpApiConsumerKey::prefix($plaintext),
            'key_hash' => SvpApiConsumerKey::hashPlaintext($plaintext),
            'expires_at' => $data['expires_at'] ?? null,
            'rate_limit_per_minute' => (int) $data['rate_limit_per_minute'],
        ]);

        return back()
            ->with('success', 'SVP API key created. Copy it now; it will not be shown again.')
            ->with('created_svp_api_key', $plaintext);
    }

    public function revoke(SvpApiConsumerKey $apiKey): RedirectResponse
    {
        $apiKey->forceFill(['revoked_at' => now()])->saveQuietly();

        return back()->with('success', 'SVP API key revoked.');
    }
}
