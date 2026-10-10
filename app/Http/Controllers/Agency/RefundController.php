<?php

namespace App\Http\Controllers\Agency;

use App\Http\Controllers\Controller;
use App\Models\RefundRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RefundController extends Controller
{
    public function __construct()
    {
        $this->middleware('agency.scope');
    }

    public function index()
    {
        $agencyId = Auth::user()->agency_id;

        return view('agency.refunds.index', [
            'refunds' => RefundRequest::with('booking')
                ->where('agency_id', $agencyId)
                ->latest()
                ->paginate(10),
        ]);
    }

    public function create()
    {
        abort(403, 'Portal-fee refunds are disabled.');
    }

    public function store(Request $request)
    {
        abort(403, 'Portal-fee refunds are disabled.');
    }
}
