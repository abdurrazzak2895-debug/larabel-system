<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\AgencyWallet;
use Illuminate\Http\Request;

class AgencyController extends Controller
{
    public function index()
    {
        return view('admin.agencies.index', [
            'agencies' => Agency::with('wallet')->latest()->paginate(10),
        ]);
    }

    public function create()
    {
        return view('admin.agencies.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:100', 'unique:agencies,code'],
        ]);

        $agency = Agency::create([
            'name'   => $data['name'],
            'code'   => $data['code'],
            'status' => true,
        ]);

        AgencyWallet::create([
            'agency_id'         => $agency->id,
            'available_balance' => 0,
            'reserved_balance'  => 0,
            'credit_limit'      => 0,
        ]);

        return redirect()->route('admin.agencies.index')->with('success', "Agency '{$agency->name}' created.");
    }

    public function edit(Agency $agency)
    {
        return view('admin.agencies.edit', compact('agency'));
    }

    public function update(Request $request, Agency $agency)
    {
        $data = $request->validate([
            'name'   => ['required', 'string', 'max:255'],
            'status' => ['boolean'],
        ]);

        $agency->update($data);

        return back()->with('success', "Agency '{$agency->name}' updated.");
    }

    public function suspend(Agency $agency)
    {
        $agency->update(['status' => false]);
        return back()->with('success', "Agency '{$agency->name}' suspended.");
    }

    public function activate(Agency $agency)
    {
        $agency->update(['status' => true]);
        return back()->with('success', "Agency '{$agency->name}' activated.");
    }

    public function destroy(Agency $agency)
    {
        $agency->delete();
        return redirect()->route('admin.agencies.index')->with('success', "Agency '{$agency->name}' deleted.");
    }
}