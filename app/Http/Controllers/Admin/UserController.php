<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\User;
use App\Models\Role;
use App\Services\UserWalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index()
    {
        return view('admin.users.index', [
            'users' => User::with('agency')->latest()->paginate(20),
        ]);
    }

    public function create()
    {
        return view('admin.users.create', [
            'agencies' => Agency::where('status', true)->get(),
            'roles' => Role::whereIn('slug', ['agency-manager', 'agency-user', 'agency-accountant'])->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'agency_id' => ['required', 'exists:agencies,id'],
            'name'      => ['required', 'string', 'max:255'],
            'email'     => ['required', 'email', 'unique:users,email'],
            'password'  => ['required', 'string', 'min:8', 'confirmed'],
            'status'    => ['boolean'],
            'role_id'   => ['required', 'integer', 'exists:roles,id'],
            'portal_booking_fee' => ['nullable', 'numeric', 'min:0'],
        ]);

        $user = User::create([
            'agency_id' => $data['agency_id'],
            'name'      => $data['name'],
            'email'     => $data['email'],
            'password'  => Hash::make($data['password']),
            'status'    => $data['status'] ?? true,
            'portal_booking_fee' => $data['portal_booking_fee'] ?? null,
        ]);

        $user->assignRole(Role::findOrFail($data['role_id'])->name);
        app(UserWalletService::class)->getWallet((int) $user->id);

        return redirect()->route('admin.dashboard')->with('success', "User account '{$user->name}' created successfully.");
    }

    public function edit(User $user)
    {
        return view('admin.users.edit', [
            'user' => $user->load('roles'),
            'agencies' => Agency::where('status', true)->get(),
            'roles' => Role::whereIn('slug', ['agency-manager', 'agency-user', 'agency-accountant'])->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'agency_id' => ['required', 'exists:agencies,id'],
            'name'      => ['required', 'string', 'max:255'],
            'email'     => ['required', 'email', "unique:users,email,{$user->id}"],
                        'status' => ['boolean'],
            'role_id' => ['required', 'integer', 'exists:roles,id'],
            'portal_booking_fee' => ['nullable', 'numeric', 'min:0'],
        ]);

        $user->update(collect($data)->except('role_id')->all());
        $user->roles()->sync([Role::findOrFail($data['role_id'])->id]);


        return back()->with('success', 'User updated.');
    }

    public function disable(User $user)
    {
        $user->update(['status' => false]);
        return back()->with('success', 'User disabled.');
    }

    public function resetPassword(Request $request, User $user)
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user->update(['password' => Hash::make($data['password'])]);

        return back()->with('success', 'Password reset for ' . $user->name);
    }
}