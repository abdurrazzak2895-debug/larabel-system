<?php

namespace App\Http\Controllers\Agency;

use App\Http\Controllers\Controller;
use App\Models\User;
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
        $agencyId = Auth::user()->agency_id;

        return view('agency.users.index', [
            'users' => User::where('agency_id', $agencyId)->latest()->paginate(10),
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
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'status'   => ['boolean'],
        ]);

        User::create([
            'agency_id' => Auth::user()->agency_id,
            'name'      => $data['name'],
            'email'     => $data['email'],
            'password'  => Hash::make($data['password']),
            'status'    => $data['status'] ?? true,
        ]);

        return redirect()->route('agency.users.index')->with('success', 'User created.');
    }

    public function edit(User $user)
    {
        return view('agency.users.edit', compact('user'));
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name'   => ['required', 'string', 'max:255'],
            'email'  => ['required', 'email', "unique:users,email,{$user->id}"],
            'status' => ['boolean'],
        ]);

        $user->update($data);

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