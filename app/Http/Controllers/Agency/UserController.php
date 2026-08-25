<?php

namespace App\Http\Controllers\Agency;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\UserWalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function __construct()
    {
        $this->middleware('agency.scope');
    }

    public function index()
    {
        $agencyId = (int) Auth::user()->agency_id;

        return view('agency.users.index', [
            'users' => User::with('wallet')->where('agency_id', $agencyId)->latest()->paginate(10),
        ]);
    }

    public function create()
    {
        return view('agency.users.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'unique:users,email'],
            'password'            => ['required', 'string', 'min:8', 'confirmed'],
            'status'              => ['boolean'],
            'portal_booking_fee'  => ['nullable', 'numeric', 'min:0'],
        ]);

        $user = User::create([
            'agency_id' => Auth::user()->agency_id,
            'name'      => $data['name'],
            'email'     => $data['email'],
            'password'  => Hash::make($data['password']),
            'status'    => $data['status'] ?? true,
            'portal_booking_fee' => $data['portal_booking_fee'] ?? null,
        ]);

        $user->assignRole('Agency User');
        app(UserWalletService::class)->getWallet((int) $user->id);

        return redirect()->route('agency.users.index')->with('success', 'User created.');
    }

    public function edit(User $user)
    {
        $this->assertAgencyUser($user);

        return view('agency.users.edit', compact('user'));
    }

    public function update(Request $request, User $user)
    {
        $this->assertAgencyUser($user);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', "unique:users,email,{$user->id}"],
            'status' => ['boolean'],
            'portal_booking_fee' => ['nullable', 'numeric', 'min:0'],
        ]);

        $user->update($data);

        return back()->with('success', 'User updated.');
    }

    public function disable(User $user)
    {
        $this->assertAgencyUser($user);
        $user->update(['status' => false]);
        return back()->with('success', 'User disabled.');
    }

    public function resetPassword(Request $request, User $user)
    {
        $this->assertAgencyUser($user);

        $data = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user->update(['password' => Hash::make($data['password'])]);

        return back()->with('success', 'Password reset for ' . $user->name);
    }

    public function adjustWallet(Request $request, User $user)
    {
        $this->assertAgencyUser($user);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'not_in:0'],
            'reference' => ['nullable', 'string', 'max:255'],
        ]);

        app(UserWalletService::class)->manualAdjust(
            (int) $user->id,
            (float) $data['amount'],
            $data['reference'] ?? 'agency-user-wallet-adjustment',
            ['agency_id' => $user->agency_id, 'actor_id' => Auth::id()]
        );

        return back()->with('success', 'User wallet updated.');
    }

    private function assertAgencyUser(User $user): void
    {
        abort_unless((int) $user->agency_id === (int) Auth::user()->agency_id, 403);
    }
}