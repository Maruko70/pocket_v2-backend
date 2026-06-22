<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 15);
        $page = $request->get('page', 1);
        $adminUserId = $request->get('admin_user_id');
        $action = $request->get('action');
        $modelType = $request->get('model_type');
        $startDate = $request->get('start_date');
        $endDate = $request->get('end_date');

        $query = ActivityLog::with('adminUser');

        if ($adminUserId) {
            $query->where('admin_user_id', $adminUserId);
        }

        if ($action) {
            $query->where('action', $action);
        }

        if ($modelType) {
            $query->where('model_type', $modelType);
        }

        if ($startDate) {
            $query->where('created_at', '>=', $startDate);
        }

        if ($endDate) {
            $query->where('created_at', '<=', $endDate);
        }

        $logs = $query->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'logs' => $logs->items(),
            'pagination' => [
                'current_page' => $logs->currentPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
                'last_page' => $logs->lastPage(),
            ],
        ]);
    }

    public function show($id)
    {
        $log = ActivityLog::with('adminUser')->findOrFail($id);

        return response()->json([
            'log' => $log,
        ]);
    }
}

