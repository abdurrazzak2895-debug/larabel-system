<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $query = AuditLog::query();

        if ($request->filled('event')) {
            $query->where('event', $request->input('event'));
        }

        if ($request->filled('actor_type')) {
            $query->where('actor_type', $request->input('actor_type'));
        }

        return view('admin.audit-logs.index', [
            'logs' => $query->latest()->paginate(25),
        ]);
    }
}