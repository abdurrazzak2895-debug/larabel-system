<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Candidate;
use App\Models\User;
use App\Services\ProfileService;
use App\Services\SvpApiService;
use App\Services\SvpOtp\OtpAutoVerifier;
use App\Services\SvpOtp\SvpAutoSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Real SVP / Takamol login: email+password -> OTP -> bearer token.
 * Accessible to all authenticated users (both agency staff and individual users).
 */
class SvpLoginController extends Controller
{
    public function __construct(
        protected SvpApiService $svp,
        protected ProfileService $profile,
        protected SvpAutoSession $autoSession,
    )
    {
    }

    /**
     * Keep the legacy URL as a compatibility redirect. SVP authentication is
     * now handled only by the inline modal on the authenticated dashboard.
     */
    public function showLoginForm(Request $request)
    {
        $user = Auth::guard('web')->user();
        if (! $user instanceof User) {
            return redirect()->route('login');
        }

        // Preserve force-reconnect behavior, but keep the user on the private
        // dashboard where the inline credential/OTP modal is rendered.
        if ($request->boolean('force')) {
            $this->autoSession->forgetCurrent($request);
        }

        return redirect()->route('user.dashboard', ['open_svp' => '1']);
    }

    /**
     * Start a reconnect for an owned candidate using credentials encrypted at
     * rest. The password is never returned to the browser; only the OTP step
     * is exposed to the user.
     */
    public function reconnect(Request $request, Candidate $candidate)
    {
        $user = Auth::guard('web')->user();
        if (! $user instanceof User) {
            return response()->json([
                'message' => 'Sign in to the portal before reconnecting your SVP account.',
            ], 401);
        }

        abort_unless((int) $candidate->user_id === (int) $user->id, 404);

        if (! $candidate->is_active) {
            return response()->json([
                'message' => 'Activate this SVP profile before reconnecting it.',
            ], 422);
        }

        $email = trim((string) ($candidate->svp_login_email ?: $candidate->email));
        $password = (string) $candidate->svp_login_password;

        if ($email === '' || $password === '') {
            return response()->json([
                'status' => 'credentials_required',
                'candidate_id' => $candidate->id,
                'email' => $email,
                'message' => 'Enter this SVP account password once. It will be encrypted for future reconnects.',
            ], 422);
        }

        try {
            $result = $this->svp->login($email, $password, 'email');
        } catch (\Throwable $e) {
            Log::error('SVP reconnect failed', ['error' => $e->getMessage()]);

            return response()->json([
                'message' => 'Takamol SVP is temporarily unreachable. Please try again in a few minutes.',
            ], 503);
        }

        if ($result['status'] >= 400) {
            return response()->json([
                'message' => $this->authenticationErrorMessage((int) $result['status'], $result['body']),
            ], 422);
        }

        $request->session()->put(SvpAutoSession::sessionKey('login', $request), [
            'email' => $email,
            'password' => $password,
            'otp_method' => 'email',
            'candidate_id' => $candidate->id,
        ]);

        return response()->json([
            'status' => 'otp_required',
            'candidate_id' => $candidate->id,
            'email' => $email,
            'message' => 'OTP sent. Enter the code from your SVP email.',
        ]);
    }

    /**
     * Step 1 — hit SVP /sessions/login, then show OTP form.
     */
    public function login(Request $request)
    {
        if (! Auth::guard('web')->check()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Sign in to the portal before connecting your SVP account.',
                ], 401);
            }

            return redirect()->route('login')->with('status', 'Sign in to the portal before connecting your SVP account.');
        }

        $credentials = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
            'candidate_id' => ['nullable', 'integer'],
        ]);

        $candidateId = (int) ($credentials['candidate_id'] ?? 0);
        if ($candidateId > 0) {
            abort_unless(
                Candidate::where('id', $candidateId)->where('user_id', Auth::guard('web')->id())->exists(),
                404,
            );
        }

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
                'email' => $this->authenticationErrorMessage((int) $result['status'], $result['body']),
            ]);
        }

        // Store credentials in session for OTP step (never persist).
        $request->session()->put(SvpAutoSession::sessionKey('login', $request), [
            'email'    => $credentials['email'],
            'password' => $credentials['password'],
            'otp_method' => $request->input('otp_method', 'email'),
            'candidate_id' => $candidateId > 0 ? $candidateId : null,
        ]);

        // Email OTP automation: when a mailbox is configured, read the code SVP
        // just mailed and finish the login instead of showing the manual form.
        $otpMethod = $request->input('otp_method', 'email');
        $autoVerifier = app(OtpAutoVerifier::class);

        if ($otpMethod === 'email' && $autoVerifier->enabled()) {
            $code = $autoVerifier->awaitCode(time() - 60);

            if ($code !== null) {
                Log::info('SVP OTP auto-verified from mailbox', ['source' => $code['source']]);

                return $this->verifyOtp($request->merge([
                    'otp_code'   => $code['code'],
                    'otp_method' => 'email',
                ]));
            }

            Log::warning('SVP OTP automation found no code; falling back to the manual OTP form');
        }

        if ($request->expectsJson()) {
            return response()->json([
                'status' => 'otp_required',
                'message' => 'OTP sent. Enter the code from your SVP email.',
                'email' => $credentials['email'],
            ]);
        }

        return redirect()->route('svp.otp.form');
    }

    /**
     * Show OTP entry form.
     */
    public function showOtpForm(Request $request)
    {
        if (! Auth::guard('web')->check()) {
            return redirect()->route('login')->with('status', 'Sign in to the portal before verifying your SVP account.');
        }

        if (! $request->session()->has(SvpAutoSession::sessionKey('login', $request))) {
            return redirect()->route('svp.login.form');
        }

        return view('auth.svp-otp');
    }

    /**
     * Resend OTP — re-submits credentials to SVP.
     */
    public function resendOtp(Request $request)
    {
        if (! Auth::guard('web')->check()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Sign in to the portal before resending the SVP OTP.',
                ], 401);
            }

            return redirect()->route('login')->with('status', 'Sign in to the portal before resending the SVP OTP.');
        }

        $svpLogin = $request->session()->get(SvpAutoSession::sessionKey('login', $request));
        if (! $svpLogin) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Your SVP login session expired. Start the connection again.',
                ], 422);
            }

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
                    'otp_code' => $this->authenticationErrorMessage((int) $result['status'], $result['body'], true),
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

        if ($request->expectsJson()) {
            return response()->json([
                'status' => 'otp_resent',
                'message' => 'A new OTP has been sent to your email.',
            ]);
        }

        return back()->with('status', 'A new OTP has been sent to your email.');
    }

    /**
     * Step 2 — verify OTP with SVP, obtain token, log in local user.
     */
    public function verifyOtp(Request $request)
    {
        $user = Auth::guard('web')->user();
        if (! $user instanceof User) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Sign in to the portal before verifying your SVP account.',
                ], 401);
            }

            return redirect()->route('login')->with('status', 'Sign in to the portal before connecting your SVP account.');
        }

        $svpLogin = $request->session()->get(SvpAutoSession::sessionKey('login', $request));
        if (! $svpLogin) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Your SVP login session expired. Start the connection again.',
                ], 422);
            }

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
            Log::warning('SVP OTP response missing token', [
                'status' => $result['status'],
                'response_keys' => is_array($result['body'] ?? null) ? array_keys($result['body']) : [],
            ]);
            throw ValidationException::withMessages([
                'otp_code' => 'SVP did not return an access token. Please try the login again.',
            ]);
        }

        // Keep SVP authentication scoped to the already authenticated portal user.
        // The external SVP identity is stored on that user's Candidate record; it
        // must never create a local User, Agency, or Agency wallet.
        $request->session()->regenerate();
        $this->autoSession->store(
            $request,
            $token,
            data_get($result['body'], 'access_payload.csrf'),
            (string) ($this->extractSvpUserId($result['body']) ?? '')
        );
        $request->session()->forget(SvpAutoSession::sessionKey('login', $request));

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

        $loginPayload = is_array($result['body'] ?? null) ? $result['body'] : [];
        $loginProfile = $this->extractProfileRecord($loginPayload);
        $loginSvpUserId = $this->extractSvpUserId($loginPayload);
        if ($loginSvpUserId !== '') {
            $request->session()->put(SvpAutoSession::sessionKey('user_id', $request), $loginSvpUserId);
        }

        // The live /profile response contains the complete personal profile but
        // omits the SVP account ID. Preserve that profile, then supplement its
        // missing ID from the OTP response envelope. Previously the non-empty
        // profile prevented the OTP fallback from running, leaving candidates
        // persisted with a null svp_user_id and unusable for reservations.
        if ($profile === []) {
            $profile = $loginProfile;
        }
        if ($loginSvpUserId !== '' && $this->extractSvpUserId($profile) === '') {
            $profile['svp_user_id'] = $loginSvpUserId;
        }
        // Some successful OTP responses contain only access_payload.user.id;
        // /profile may be empty or unauthorized even though the token is valid.
        // Still sync/reactivate the existing local candidate so the booking
        // dropdown is not empty after a successful SVP login.
        if ($profile === [] && $loginSvpUserId !== '') {
            $profile = ['svp_user_id' => $loginSvpUserId];
        }

        $candidate = null;
        $requestedCandidateId = (int) ($svpLogin['candidate_id'] ?? 0);
        $requestedCandidate = $requestedCandidateId > 0
            ? Candidate::where('id', $requestedCandidateId)->where('user_id', $user->id)->first()
            : null;

        if ($requestedCandidate && $requestedCandidate->svp_user_id && $loginSvpUserId !== ''
            && (string) $requestedCandidate->svp_user_id !== $loginSvpUserId) {
            throw ValidationException::withMessages([
                'otp_code' => 'These SVP credentials belong to a different saved profile.',
            ]);
        }

        if ($profile !== []) {
            try {
                $candidate = $this->syncCandidateFromProfile($user, $profile);
            } catch (\Throwable $e) {
                Log::warning('SVP candidate persistence after login failed', ['error' => $e->getMessage()]);
            }
        }

        if ($requestedCandidate && $candidate instanceof Candidate && $candidate->id !== $requestedCandidate->id) {
            throw ValidationException::withMessages([
                'otp_code' => 'This SVP account is already saved under another profile.',
            ]);
        }

        if ($candidate instanceof Candidate) {
            $candidate->forceFill([
                'svp_login_email' => $svpLogin['email'],
                'svp_login_password' => $svpLogin['password'],
            ])->save();

            $this->autoSession->storeForCandidate(
                $request,
                $candidate->id,
                $token,
                data_get($result['body'], 'access_payload.csrf'),
                $loginSvpUserId !== '' ? $loginSvpUserId : (string) $candidate->svp_user_id,
            );
        }

        // All portal users land on their private user panel after SVP login.
        $home = route('user.dashboard');

        if ($request->expectsJson()) {
            return response()->json([
                'status' => 'authenticated',
                'message' => 'SVP account connected successfully.',
                'redirect' => $home,
            ]);
        }

        return redirect()->intended($home);
    }

    private function authenticationErrorMessage(int $status, mixed $body, bool $resend = false): string
    {
        if ($status === 404) {
            return 'The SVP authentication endpoint was not found. Check the SVP base URL and tenant configuration.';
        }

        if ($status === 429) {
            return 'Too many SVP authentication attempts. Wait a few minutes before requesting another OTP.';
        }

        if ($status >= 500) {
            return 'Takamol SVP is temporarily unavailable. Please try again in a few minutes.';
        }

        $message = data_get($body, 'message');
        if (is_string($message) && trim($message) !== '') {
            return trim($message);
        }

        return $resend ? 'Unable to resend the SVP OTP.' : 'SVP credentials were rejected. Check the email and password, then request a new OTP.';
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

    private function syncCandidateFromProfile(User $user, array $profile): Candidate
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
            'is_active'   => true,
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

        return $candidate;
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
        foreach ([
            'svp_user_id',
            'svpUserId',
            'user_id',
            'account_id',
            'individual_id',
            'id',
            'user.id',
            'profile.id',
            'profile.user_id',
            'profile.svp_user_id',
            'account.id',
            'individual.id',
            'access_payload.user.id',
            'access_payload.user_id',
            'access_payload.account_id',
            'access_payload.id',
            'access.user.id',
            'access.user_id',
            'data.user.id',
            'data.profile.id',
        ] as $path) {
            $value = data_get($profile, $path);
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        foreach (['data', 'user', 'profile', 'account', 'individual', 'access_payload', 'access'] as $key) {
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
