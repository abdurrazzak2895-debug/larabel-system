<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SvpAvailabilityAccount;
use App\Services\SvpAvailabilityTokenResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SvpAvailabilityAccountController extends Controller
{
    public function __construct(private readonly SvpAvailabilityTokenResolver $tokens)
    {
    }

    public function index(): View
    {
        return view('admin.svp-availability-accounts.index', [
            'accounts' => $this->tokens->accounts(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:190'],
        ]);

        SvpAvailabilityAccount::query()->create($data + ['active' => true]);

        return back()->with('success', 'Backend SVP availability account created. Seed its token next.');
    }

    public function seedToken(Request $request, SvpAvailabilityAccount $account): RedirectResponse
    {
        $data = $request->validate([
            'access_token' => ['required', 'string', 'max:10000'],
            'refresh_token' => ['nullable', 'string', 'max:10000'],
            'token_expires_at' => ['nullable', 'date'],
        ]);

        $this->tokens->rememberToken(
            $account,
            trim($data['access_token']),
            filled($data['refresh_token'] ?? null) ? trim($data['refresh_token']) : null,
            $data['token_expires_at'] ?? null,
        );

        return back()->with('success', 'SVP availability token encrypted and cached.');
    }

    public function deactivate(SvpAvailabilityAccount $account): RedirectResponse
    {
        $account->forceFill(['active' => false])->save();
        $this->tokens->invalidate($account->getKey(), 'Account deactivated by admin.');

        return back()->with('success', 'Backend SVP availability account deactivated.');
    }

    public function activate(SvpAvailabilityAccount $account): RedirectResponse
    {
        $account->forceFill(['active' => true, 'last_error' => null])->save();
        $this->tokens->invalidate();

        return back()->with('success', 'Backend SVP availability account activated.');
    }
}
