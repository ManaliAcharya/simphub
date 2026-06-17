<?php

namespace Modules\BookSync\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\BookSync\Models\BookSyncApiLog;

class ApiLogController extends Controller
{
    public function index(Request $request)
    {
        $query = BookSyncApiLog::with('client', 'merchant')
            ->orderByDesc('created_at');

        if ($request->filled('client_id')) {
            $query->where('client_id', $request->query('client_id'));
        }

        if ($request->filled('merchant_id')) {
            $query->where('merchant_id', $request->query('merchant_id'));
        }

        if ($request->filled('status')) {
            $query->where('http_status', $request->query('status'));
        }

        if ($request->filled('rejection_reason')) {
            $value = $request->query('rejection_reason');
            if ($value === 'success') {
                $query->whereNull('rejection_reason');
            } else {
                $query->where('rejection_reason', $value);
            }
        }

        $logs = $query->paginate(50)->withQueryString();

        return view('booksync::admin.logs.index', compact('logs'));
    }
}
