<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\AgencyWallet;
use App\Models\Candidate;
use App\Models\User;
use App\Services\ProfileService;
use App\Services\SvpApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Real SVP / Takamol login: email+password -> OTP -> bearer token.
 * Accessible to all authenticated users (both agency staff and individual users).
 */
class SvpLoginController extends Controller
{
    public function __construct(protected SvpApiService $svp, protected ProfileService $profile)
    {
    }

    /**
     * Show SVP login form.
     * Public — any user can attempt SVP authentication directly.
     */
    public function showLoginForm(Request $request)
    {
        // Allow an authenticated user to replace an expired/stale SVP token.
        // The booking page can link here with ?force=1 after an external API
        // authentication failure; no credentials are persisted beyond the OTP step.
        if ($request->boolean('force')) {
            $request->session()->forget(['svp_token', 'svp_csrf', 'svp_login']);
        } elseif ($request->session()->has('svp_token')) {
            return redirect()->route('agency.dashboard');
        }

        return view('auth.svp-login');
    }

    /**
     * Step 1 — hit SVP /sessions/login, then show OTP form.
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        try {
            $result = $this->svp->login(
                $credentials['email'],
                $credentials['password'],
                $request->input('otp_method', 'email'),
            );
        } catch (\Throwable $e) {
            Log::error('SVP login failed', ['error' => $e->getMessage()]);
            throw ValidationException::withMessages([
                'email' => 'Takamol SVP is temporarily unreachable (external service outage, not an account issue). Please try again in a few minutes.',
            ]);
        }

        if ($result['status'] >= 400) {
            throw ValidationException::withMessages([
                'email' => data_get($result['body'], 'message', 'SVP credentials rejected.'),
            ]);
        }

        // Store credentials in session for OTP step (never persist).
        $request->session()->put('svp_login', [
            'email'    => $credentials['email'],
            'password' => $credentials['password'],
            'otp_method' => $request->input('otp_method', 'email'),
        ]);

        return redirect()->route('svp.otp.form');
    }

    /**
     * Show OTP entry form.
     */
    public function showOtpForm(Request $request)
    {
        if (! $request->session()->has('svp_login')) {
            return redirect()->route('svp.login.form');
        }

        return view('auth.svp-otp');
    }

    /**
     * Resend OTP — re-submits credentials to SVP.
     */
    public function resendOtp(Request $request)
    {
        $svpLogin = $request->session()->get('svp_login');
        if (! $svpLogin) {
            return redirect()->route('svp.login.form');
        }

        try {
            $result = $this->svp->login(
                $svpLogin['email'],
                $svpLogin['password'],
                $svpLogin['otp_method'] ?? 'email',
            );

            if ($result['status'] >= 400) {
                throw ValidationException::withMessages([
                    'otp_code' => data_get($result['body'], 'message', 'Unable to resend OTP.'),
                ]);
            }
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('SVP OTP resend failed', ['error' => $e->getMessage()]);
            throw ValidationException::withMessages([
                'otp_code' => 'Takamol SVP is temporarily unreachable (external service outage). Please try again in a few minutes.',
            ]);
        }

        return back()->with('status', 'A new OTP has been sent to your email.');
    }

    /**
     * Step 2 — verify OTP with SVP, obtain token, log in local user.
     */
    public function verifyOtp(Request $request)
    {
        $svpLogin = $request->session()->get('svp_login');
        if (! $svpLogin) {
            return redirect()->route('svp.login.form');
        }

        $credentials = $request->validate([
            'otp_code' => ['required', 'numeric'],
        ]);

        try {
            $result = $this->svp->verifyOtp(
                $svpLogin['email'],
                $svpLogin['password'],
                $credentials['otp_code'],
                $svpLogin['otp_method'] ?? 'email',
            );
        } catch (\Throwable $e) {
            Log::error('SVP OTP verify failed', ['error' => $e->getMessage()]);
            throw ValidationException::withMessages([
                'otp_code' => 'Takamol SVP is temporarily unreachable (external service outage). Please try again in a few minutes.',
            ]);
        }

        if ($result['status'] >= 400) {
            throw ValidationException::withMessages([
                'otp_code' => data_get($result['body'], 'message', 'OTP code invalid or expired.'),
            ]);
        }

        // Recursively find the first key named token/access_token/access anywhere in the response.
        $token = $this->findToken($result['body']);

        if (! $token) {
            Log::warning('SVP OTP response missing token', ['body' => $result['body']]);
            throw ValidationException::withMessages([
                'otp_code' => 'SVP did not return an access token. Raw: ' . json_encode($result['body']),
            ]);
        }

        // Store token and CSRF in session.
        $request->session()->put('svp_token', $token);
        $request->session()->put('svp_csrf', data_get($result['body'], 'access_payload.csrf'));
        $request->session()->forget('svp_login');

        // Auto-create / find matching local user and log them in.
        $user = User::firstOrCreate(
            ['email' => $svpLogin['email']],
            [
                'name'     => data_get($result['body'], 'user.name', $svpLogin['email']),
                'username' => strstr($svpLogin['email'], '@', true) ?: 'svp_user',
                'password' => 'svp-session',
            ]
        );

        Auth::login($user);
        $request->session()->regenerate();

        // Ensure the user has an agency — auto-create one if missing.
        if (empty($user->agency_id)) {
            // Try to assign agency from SVP profile first.
            $svpAgencyId = data_get($result['body'], 'user.agency_id');

            if ($svpAgencyId && Agency::whereKey((int) $svpAgencyId)->exists()) {
                $user->update(['agency_id' => (int) $svpAgencyId]);
            } else {
                // No agency found — auto-create a personal agency.
                $agency = Agency::create([
                    'name'   => $user->name . "'s Agency",
                    'code'   => Str::upper(Str::random(6)),
                    'status' => true,
                ]);

                AgencyWallet::create([
                    'agency_id'         => $agency->id,
                    'available_balance' => 0,
                    'reserved_balance'  => 0,
                    'credit_limit'      => 0,
                ]);

                $user->update(['agency_id' => $agency->id]);
            }
        }

        // Auto-create / update candidate from SVP profile after successful login.
        // Some SVP deployments intermittently fail the follow-up profile request,
        // while the OTP response already contains a usable user/profile envelope.
        // Fall back to that response so a verified account is not left without a
        // candidate row and the booking wizard does not remain unusable.
        $profile = [];
        try {
            $profileResponse = $this->profile->profile($token);
            $profileData = $profileResponse->getData(true);
            $profile = $this->extractProfileRecord(is_array($profileData) ? $profileData : []);
        } catch (\Throwable $e) {
            Log::warning('SVP profile sync after login failed; trying OTP response fallback', ['error' => $e->getMessage()]);
        }

        if ($profile === []) {
            $profile = $this->extractProfileRecord(is_array($result['body'] ?? null) ? $result['body'] : []);
        }

        if ($profile !== []) {
            try {
                $this->syncCandidateFromProfile($user, $profile);
            } catch (\Throwable $e) {
                Log::warning('SVP candidate persistence after login failed', ['error' => $e->getMessage()]);
            }
        }

        // Agency staff land on the agency panel; standalone SVP users on the user panel.
        $home = $user->agency_id !== null
            ? route('agency.dashboard')
            : route('user.dashboard');

        return redirect()->intended($home);
    }

    /**
     * Recursively search a decoded JSON array for the first key named
     * "token", "access_token", or "access" and return its value.
     */
    protected function findToken(array $data): ?string
    {
        foreach ($data as $key => $value) {
            if (in_array($key, ['token', 'access_token', 'access'], true) && is_string($value) && $value !== '') {
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

    private function syncCandidateFromProfile(User $user, array $profile): void
    {
        $svpUserId = $this->extractSvpUserId($profile);
        $candidate = $svpUserId !== ''
            ? Candidate::where('user_id', $user->id)->where('svp_user_id', $svpUserId)->first()
            : null;

        // Reuse the existing candidate created before SVP profile sync. This is
        // important because a nullable unique key allows multiple null-ID rows,
        // and updateOrCreate([user_id, null]) cannot reliably select the row shown
        // in the booking dropdown.
        $candidate ??= Candidate::where('user_id', $user->id)
            ->whereNull('svp_user_id')
            ->latest('id')
            ->first();
        $candidate ??= new Candidate(['user_id' => $user->id]);

        $candidate->fill([
            'agency_id'   => $user->agency_id,
            'svp_user_id' => $svpUserId !== '' ? $svpUserId : $candidate->svp_user_id,
            'full_name'   => data_get($profile, 'full_name')
                ?: trim((string) data_get($profile, 'first_name', '') . ' ' . (string) data_get($profile, 'last_name', ''))
                ?: $user->name,
            'national_id' => data_get($profile, 'national_id')
                ?? data_get($profile, 'iqama')
                ?? data_get($profile, 'id_number'),
            'phone'       => data_get($profile, 'phone')
                ?? data_get($profile, 'mobile')
                ?? data_get($profile, 'phone_number'),
            'email'       => data_get($profile, 'email') ?? $user->email,
            'svp_data'    => $profile,
        ]);
        $candidate->user_id = $user->id;
        $candidate->save();
    }

    /**
     * Normalize the profile envelope returned by different SVP deployments.
     * Live responses have appeared as data, data.profile, data.user, profile,
     * and user; the booking payload needs the actual profile record.
     */
    private function extractProfileRecord(array $payload): array
    {
        foreach (['data.profile', 'data.user', 'profile', 'user', 'data'] as $path) {
            $value = data_get($payload, $path);
            if (is_array($value) && ($this->extractSvpUserId($value) !== '' || data_get($value, 'full_name'))) {
                return $value;
            }
        }

        return $this->extractSvpUserId($payload) !== '' ? $payload : [];
    }

    private function extractSvpUserId(array $profile): string
    {
        foreach (['svp_user_id', 'user_id', 'id', 'user.id', 'profile.id'] as $path) {
            $value = data_get($profile, $path);
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        foreach (['data', 'user', 'profile', 'account', 'individual'] as $key) {
            $nested = data_get($profile, $key);
            if (is_array($nested)) {
                $id = $this->extractSvpUserId($nested);
                if ($id !== '') {
                    return $id;
                }
            }
        }

        return '';
    }
}
