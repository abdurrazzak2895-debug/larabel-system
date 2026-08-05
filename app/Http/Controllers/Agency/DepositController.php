<?php

namespace App\Http\Controllers\Agency;

use App\Http\Controllers\Controller;
use App\Models\DepositRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DepositController extends Controller
{
    public function __construct()
    {
        $this->middleware('agency.scope');
    }

    public function index()
    {
        $agencyId = Auth::user()->agency_id;

        return view('agency.deposits.index', [
            'deposits' => DepositRequest::where('agency_id', $agencyId)->latest()->paginate(10),
        ]);
    }

    public function create()
    {
        return view('agency.deposits.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'amount'         => ['required', 'numeric', 'min:1'],
            'payment_method' => ['required', 'string'],
            'receipt'        => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:4096'],
        ]);

        $data['agency_id'] = Auth::user()->agency_id;

        app(\App\Services\DepositService::class)->submit($data);

        return redirect()->route('agency.deposits.index')->with('success', 'Deposit request submitted.');
    }
}