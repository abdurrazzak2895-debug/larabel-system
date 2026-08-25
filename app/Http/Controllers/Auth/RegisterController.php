<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Role;
use App\Models\User;
use App\Services\UserWalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class RegisterController extends Controller
{
    private const FIXED_AGENCY_CODE = 'SVP-7474';

    public function __construct(private UserWalletService $userWallet)
    {
    }

    public function create()
    {
        return view('auth.register');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'alpha_dash', 'min:3', 'max:50', 'unique:users,username'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'agency_code' => [
                'required', 'string', 'max:64',
                Rule::in([self::FIXED_AGENCY_CODE]),
            ],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $agency = Agency::query()
            ->where('code', self::FIXED_AGENCY_CODE)
            ->where('status', true)
            ->firstOrFail();

        $user = User::create([
            'agency_id' => $agency->id,
            'name' => $data['name'],
            'username' => $data['username'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'status' => true,
            'portal_booking_fee' => null,
        ]);

        if ($role = Role::where('slug', 'agency-user')->first()) {
            $user->assignRole($role->name);
        }

        $this->userWallet->getWallet((int) $user->id);

        return redirect()->route('login')->with('status', 'Account created successfully. You can now sign in with your username or email.');
    }
}
