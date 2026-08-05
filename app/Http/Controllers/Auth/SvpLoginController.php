<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Candidate;
use App\Models\User;
use App\Services\ProfileService;
use App\Services\SvpApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Real SVP / Takamol login: email+password -> OTP -> bearer token.
 */
class SvpLoginController extends Controller
{
    public function __construct(protected SvpApiService $svp, protected ProfileService $profile)
    {
    }

    /**
     * Show SVP login form.
     * Only accessible to authenticated users WITHOUT agency_id (regular users).
     * Agency users must use regular /login.
     */
    public function showLoginForm(Request $request)
    {
        // If already authenticated, check if they're an agency user
        $user = $request->user('web');
        
        if ($user) {
            // Agency users cannot use SVP login - redirect to their dashboard
            if ($user->agency_id !== null) {
                return redirect()->route('agency.dashboard')
                    ->with('error', 'Agency users must use the regular login. SVP login is only for individual users.');
            }
            // Regular user - allow SVP login
        } else {
            // Not authenticated - redirect to regular login first
            return redirect()->route('login')
                ->with('error', 'Please log in first to access SVP authentication.');
        }

        return view('auth.svp-login');
    }

    /**
     * Step 1 — hit SVP /sessions/login, then show OTP form.
     * Only for users WITHOUT agency_id (regular users).
     */
    public function login(Request $request)
    {
        // Check if user is authenticated and is an agency user
        $user = $request->user('web');
        if ($user && $user->agency_id !== null) {
            throw ValidationException::withMessages([
                'email' => 'Agency users cannot use SVP login. Please use regular login.',
            ]);
        }

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
                'email' => 'Unable to reach SVP. Please try again.',
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
     * Only for users WITHOUT agency_id (regular users).
     */
    public function showOtpForm(Request $request)
    {
        // Check if user is authenticated and is an agency user
        $user = $request->user('web');
        if ($user && $user->agency_id !== null) {
            return redirect()->route('agency.dashboard')
                ->with('error', 'Agency users cannot use SVP login. Please use regular login.');
        }

        if (! $request->session()->has('svp_login')) {
            return redirect()->route('svp.login.form');
        }

        return view('auth.svp-otp');
    }

    /**
     * Resend OTP — re-submits credentials to SVP.
     * Only for users WITHOUT agency_id (regular users).
     */
    public function resendOtp(Request $request)
    {
        // Check if user is authenticated and is an agency user
        $user = $request->user('web');
        if ($user && $user->agency_id !== null) {
            throw ValidationException::withMessages([
                'otp_code' => 'Agency users cannot use SVP login. Please use regular login.',
            ]);
        }

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
                'otp_code' => 'Unable to reach SVP. Please try again.',
            ]);
        }

        return back()->with('status', 'A new OTP has been sent to your email.');
    }

    /**
     * Step 2 — verify OTP with SVP, obtain token, log in local user.
     * Only for users WITHOUT agency_id (regular users).
     */
    public function verifyOtp(Request $request)
    {
        // Check if user is authenticated and is an agency user
        $user = $request->user('web');
        if ($user && $user->agency_id !== null) {
            throw ValidationException::withMessages([
                'otp_code' => 'Agency users cannot use SVP login. Please use regular login.',
            ]);
        }

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
                'otp_code' => 'Unable to reach SVP. Please try again.',
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

        // Ensure agency assignment if the SVP profile contains one
        if (empty($user->agency_id) && data_get($result['body'], 'user.agency_id')) {
            $user->update(['agency_id' => (int) data_get($result['body'], 'user.agency_id')]);
        }

        Auth::login($user);
        $request->session()->regenerate();

        // Auto-create / update candidate from SVP profile after successful login.
        try {
            $profileResponse = $this->profile->profile($token);
            $profileData = $profileResponse->getData(true);
            $profile = data_get($profileData, 'data', $profileData);

            if (is_array($profile) && ! empty($profile)) {
                $this->syncCandidateFromProfile($user, $profile);
            }
        } catch (\Throwable $e) {
            Log::warning('SVP profile sync after login failed', ['error' => $e->getMessage()]);
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
        $svpUserId = (string) data_get($profile, 'id', data_get($profile, 'user_id', ''));

        Candidate::updateOrCreate(
            [
                'user_id'    => $user->id,
                'svp_user_id' => $svpUserId ?: null,
            ],
            [
                'agency_id'  => $user->agency_id,
                'full_name'  => data_get($profile, 'full_name')
                    ?? trim((data_get($profile, 'first_name', '') . ' ' . data_get($profile, 'last_name', '')))
                    ?? $user->name,
                'national_id'=> data_get($profile, 'national_id')
                    ?? data_get($profile, 'iqama')
                    ?? data_get($profile, 'id_number')
                    ?? null,
                'phone'      => data_get($profile, 'phone')
                    ?? data_get($profile, 'mobile')
                    ?? data_get($profile, 'phone_number')
                    ?? null,
                'email'      => data_get($profile, 'email') ?? $user->email,
                'svp_data'   => $profile,
            ]
        );
    }
}
