<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PortalAvailabilityApiKey;
use App\Models\PortalAvailabilityCredential;
use App\Services\PortalAvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

final class PortalAvailabilityController extends Controller
{
    public function __construct(
        private readonly PortalAvailabilityService $availability,
    ) {
    }

    public function index(): View
    {
        return view('admin.portal-availability.index', [
            'credentials' => $this->availability->credentials(),
            'apiKeys' => PortalAvailabilityApiKey::query()
                ->with('credential')
                ->orderByDesc('created_at')
                ->get(),
        ]);
    }

    public function storeCredential(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'portal_account_id' => ['required', 'string', 'max:120'],
            'session_cookie' => ['required', 'string', 'max:10000'],
            'expires_at' => ['nullable', 'date'],
        ]);

        PortalAvailabilityCredential::query()->create([
            'name' => trim($data['name']),
            'portal_account_id' => trim($data['portal_account_id']),
            'session_cookie' => trim($data['session_cookie']),
            'expires_at' => $data['expires_at'] ?? null,
            'active' => true,
        ]);

        return back()->with('success', 'Portal session credential encrypted and saved.');
    }

    public function updateCredential(Request $request, PortalAvailabilityCredential $credential): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'portal_account_id' => ['required', 'string', 'max:120'],
            'session_cookie' => ['nullable', 'string', 'max:10000'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $updates = [
            'name' => trim($data['name']),
            'portal_account_id' => trim($data['portal_account_id']),
            'expires_at' => $data['expires_at'] ?? null,
            'last_error' => null,
        ];
        if (filled($data['session_cookie'] ?? null)) {
            $updates['session_cookie'] = trim($data['session_cookie']);
        }

        $credential->forceFill($updates)->save();

        return back()->with('success', 'Portal credential details updated securely.');
    }

    public function refreshCredential(PortalAvailabilityCredential $credential): RedirectResponse
    {
        try {
            $result = $this->availability->refreshCredential($credential);

            return back()->with('success', sprintf(
                'Portal session refreshed for %s%s.',
                $credential->name,
                ($result['rotated'] ?? false) ? ' and the encrypted cookie was rotated' : '',
            ));
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors([
                'portal_refresh' => 'Portal session refresh failed. Check the saved session and upstream portal availability.',
            ]);
        }
    }

    public function deactivate(PortalAvailabilityCredential $credential): RedirectResponse
    {
        $credential->forceFill(['active' => false])->save();
        return back()->with('success', 'Portal availability credential deactivated.');
    }

    public function activate(PortalAvailabilityCredential $credential): RedirectResponse
    {
        $credential->forceFill(['active' => true, 'last_error' => null])->save();
        return back()->with('success', 'Portal availability credential activated.');
    }

    public function storeApiKey(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'portal_availability_credential_id' => ['required', 'integer', 'exists:portal_availability_credentials,id'],
            'expires_at' => ['nullable', 'date'],
            'rate_limit_per_minute' => ['required', 'integer', 'min:1', 'max:1000'],
        ]);

        $credential = PortalAvailabilityCredential::query()->findOrFail($data['portal_availability_credential_id']);
        if (! $credential->hasUsableSession()) {
            return back()->withErrors(['portal_availability_credential_id' => 'Choose an active portal session with a valid cookie.']);
        }

        $plaintext = PortalAvailabilityApiKey::generatePlaintext();
        PortalAvailabilityApiKey::query()->create([
            'portal_availability_credential_id' => $credential->id,
            'name' => trim($data['name']),
            'key_prefix' => PortalAvailabilityApiKey::prefix($plaintext),
            'key_hash' => PortalAvailabilityApiKey::hashPlaintext($plaintext),
            'expires_at' => $data['expires_at'] ?? null,
            'rate_limit_per_minute' => (int) $data['rate_limit_per_minute'],
        ]);

        return back()
            ->with('success', 'API key created. Copy it now; it will not be shown again.')
            ->with('created_api_key', $plaintext);
    }

    public function revokeApiKey(PortalAvailabilityApiKey $apiKey): RedirectResponse
    {
        $apiKey->forceFill(['revoked_at' => now()])->saveQuietly();

        return back()->with('success', 'External API key revoked.');
    }

    public function occupations(Request $request): JsonResponse
    {
        $credentialId = $this->credentialId($request);

        try {
            return response()->json([
                'success' => true,
                'data' => $this->availability->occupations($credentialId),
            ]);
        } catch (Throwable $exception) {
            report($exception);
            return $this->failure($exception, 'Portal occupation lookup failed.');
        }
    }

    public function dates(Request $request): JsonResponse
    {
        $data = $request->validate([
            'credential_id' => ['required', 'integer', 'exists:portal_availability_credentials,id'],
            'category_id' => ['required', 'integer', 'min:1'],
            'start_from' => ['required', 'date_format:Y-m-d'],
        ]);

        try {
            return response()->json([
                'success' => true,
                'data' => $this->availability->searchDates(
                    (int) $data['credential_id'],
                    $data['category_id'],
                    $data['start_from'],
                ),
            ]);
        } catch (Throwable $exception) {
            report($exception);
            return $this->failure($exception, 'Portal date availability lookup failed.');
        }
    }

    public function centers(Request $request): JsonResponse
    {
        $data = $request->validate([
            'credential_id' => ['required', 'integer', 'exists:portal_availability_credentials,id'],
            'category_id' => ['required', 'integer', 'min:1'],
            'city' => ['required', 'string', 'max:150'],
            'date' => ['required', 'date_format:Y-m-d'],
            'occupation_id' => ['required', 'integer'],
            'language_code' => ['required', 'string', 'max:120'],
        ]);

        try {
            return response()->json([
                'success' => true,
                'data' => $this->availability->centers(
                    (int) $data['credential_id'],
                    $data['category_id'],
                    trim($data['city']),
                    $data['date'],
                    (int) $data['occupation_id'],
                    trim($data['language_code']),
                ),
            ]);
        } catch (Throwable $exception) {
            report($exception);
            return $this->failure($exception, 'Portal center availability lookup failed.');
        }
    }

    public function externalOccupations(Request $request): JsonResponse
    {
        try {
            $result = $this->availability->occupations($this->externalCredentialId($request));

            return response()->json([
                'success' => true,
                'data' => $result['data'],
                'fetched_at' => $result['fetched_at'],
            ]);
        } catch (Throwable $exception) {
            report($exception);
            return $this->failure($exception, 'Portal occupation lookup failed.');
        }
    }

    public function externalDates(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'integer', 'min:1'],
            'start_from' => ['required', 'date_format:Y-m-d'],
        ]);

        try {
            $result = $this->availability->searchDates(
                $this->externalCredentialId($request),
                $data['category_id'],
                $data['start_from'],
            );

            return response()->json([
                'success' => true,
                'data' => $result['data'],
                'fetched_at' => $result['fetched_at'],
            ]);
        } catch (Throwable $exception) {
            report($exception);
            return $this->failure($exception, 'Portal date availability lookup failed.');
        }
    }

    public function externalCenters(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'integer', 'min:1'],
            'city' => ['required', 'string', 'max:150'],
            'date' => ['required', 'date_format:Y-m-d'],
            'occupation_id' => ['required', 'integer'],
            'language_code' => ['required', 'string', 'max:120'],
        ]);

        try {
            $result = $this->availability->centers(
                $this->externalCredentialId($request),
                $data['category_id'],
                trim($data['city']),
                $data['date'],
                (int) $data['occupation_id'],
                trim($data['language_code']),
            );

            return response()->json([
                'success' => true,
                'data' => $result['data'],
                'fetched_at' => $result['fetched_at'],
            ]);
        } catch (Throwable $exception) {
            report($exception);
            return $this->failure($exception, 'Portal center availability lookup failed.');
        }
    }

    private function credentialId(Request $request): int
    {
        $id = $request->integer('credential_id');
        if ($id > 0) {
            return $id;
        }

        $id = PortalAvailabilityCredential::query()->usable()->orderBy('last_used_at')->value('id');
        if (! $id) {
            throw ValidationException::withMessages([
                'credential_id' => 'No usable portal availability credential is configured.',
            ]);
        }

        return (int) $id;
    }

    private function externalCredentialId(Request $request): int
    {
        $apiKey = $request->attributes->get('portal_availability_api_key');
        if (! $apiKey instanceof PortalAvailabilityApiKey) {
            throw new \RuntimeException('External API key authentication is required.');
        }

        return (int) $apiKey->portal_availability_credential_id;
    }

    private function failure(Throwable $exception, string $fallback): JsonResponse
    {
        $message = Str::contains($exception->getMessage(), [
            'expired',
            'not authorized',
            'not configured',
            'No usable',
        ], true)
            ? $exception->getMessage()
            : $fallback;

        return response()->json([
            'success' => false,
            'message' => $message,
        ], 502);
    }
}
