<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Candidate;
use App\Services\SvpOtp\SvpAutoSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SvpProfileController extends Controller
{
    public function __construct(private SvpAutoSession $autoSession)
    {
        $this->middleware('auth.multi');
    }

    public function select(Request $request, Candidate $candidate): RedirectResponse
    {
        $candidate = $this->ownedCandidate($candidate);

        if (! $candidate->is_active) {
            return back()->with('error', 'This SVP profile is deactivated. Activate it before switching.');
        }

        if (! $this->autoSession->hasSessionForCandidate($request, $candidate->id)) {
            return redirect()->route('svp.login.form', ['connect' => 1])
                ->with('status', 'Reconnect this SVP account before switching to it.');
        }

        $this->autoSession->setActiveCandidate($request, $candidate->id);

        return back()->with('success', $candidate->full_name.' is now the active SVP profile.');
    }

    public function activate(Request $request, Candidate $candidate): RedirectResponse
    {
        $candidate = $this->ownedCandidate($candidate);
        $candidate->update(['is_active' => true]);

        if ($this->autoSession->hasSessionForCandidate($request, $candidate->id)) {
            $this->autoSession->setActiveCandidate($request, $candidate->id);
            return back()->with('success', $candidate->full_name.' has been activated and selected.');
        }

        return redirect()->route('svp.login.form', ['connect' => 1])
            ->with('status', 'Profile enabled. Sign in with that SVP account to create its encrypted session.');
    }

    public function deactivate(Request $request, Candidate $candidate): RedirectResponse
    {
        $candidate = $this->ownedCandidate($candidate);
        $candidate->update(['is_active' => false]);
        $this->autoSession->forgetCandidate($request, $candidate->id);

        return back()->with('success', $candidate->full_name.' has been deactivated.');
    }

    private function ownedCandidate(Candidate $candidate): Candidate
    {
        abort_unless((int) $candidate->user_id === (int) Auth::id(), 404);

        return $candidate;
    }
}
